<?php
/**
 * @author Aaron Francis <aaron@hammerstone.dev|https://twitter.com/aarondfrancis>
 */

namespace Hammerstone\Sidecar;

use Aws\Lambda\Exception\LambdaException;
use Aws\MockHandler;
use Aws\Result;
use Hammerstone\Sidecar\Clients\LambdaClient;
use Hammerstone\Sidecar\Events\AfterFunctionExecuted;
use Hammerstone\Sidecar\Events\BeforeFunctionExecuted;
use Hammerstone\Sidecar\Exceptions\FunctionNotFoundException;
use Hammerstone\Sidecar\Exceptions\SidecarException;
use Hammerstone\Sidecar\Results\PendingResult;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Traits\Macroable;
use PHPUnit\Framework\Assert;
use Throwable;

class Manager
{
    use Macroable;

    /**
     * @var array
     */
    protected $loggers = [];

    /**
     * @var string
     */
    protected $environment;

    /**
     * @var bool
     */
    protected $sublog = false;

    protected $recording = false;

    protected $recordedExecutions = [];

    protected $mockedResponse = null;

    public function fake($mockedResponse)
    {
        $this->recording = true;
        $this->mockedResponse = $mockedResponse;
    }

    public function assertExecuted($function, $expectedParameters)
    {
        if (!$this->recording) {
            throw new SidecarException('You called assertExecuted() without calling fake()');
        }

        if (is_string($function)) {
            $function = app($function);
        }

        $executions = collect($this->recordedExecutions)->filter(function ($execution) use ($function, $expectedParameters) {
            return $execution == [
                'function' => $function->nameWithPrefix(),
                'payload' => $expectedParameters
            ];
        });

        Assert::assertTrue(
            $executions->count() > 0,
            'An expected execution was not recorded.'
        );
    }

    /**
     * @param $closure
     * @return $this
     */
    public function addLogger($closure)
    {
        $this->loggers[] = $closure;

        return $this;
    }

    /**
     * @param Command $command
     */
    public function addCommandLogger(Command $command)
    {
        $this->addLogger(function ($message) use ($command) {
            $command->info($message);
        });
    }

    /**
     * @param $message
     */
    public function log($message)
    {
        foreach ($this->loggers as $logger) {
            $logger(($this->sublog ? '          ↳' : '[Sidecar]') . " $message");
        }
    }

    /**
     * @param bool $sublog
     * @return \Closure
     */
    public function sublog()
    {
        $cached = $this->sublog;

        $undo = function () use ($cached) {
            $this->sublog = $cached;
        };

        $this->sublog = true;

        return $undo;
    }

    /**
     * @param string $environment
     */
    public function overrideEnvironment($environment)
    {
        $this->environment = $environment;
    }

    /**
     * Clear the environment override.
     */
    public function clearEnvironment()
    {
        $this->environment = null;
    }

    /**
     * @return string
     */
    public function getEnvironment()
    {
        return $this->environment ?? config('sidecar.env') ?? config('app.env');
    }

    /**
     * @param string|LambdaFunction $function
     * @param array $payload
     * @param false $async
     * @return PendingResult|Results\SettledResult
     * @throws Exceptions\SidecarException
     */
    public function execute($function, $payload = [], $async = false)
    {
        // Could be a FQCN.
        if (is_string($function)) {
            $function = app($function);
        }

        $payload = $function->preparePayload($payload);

        $method = $async ? 'invokeAsync' : 'invoke';

        event(new BeforeFunctionExecuted($function, $payload));

        $function->beforeExecution($payload);

        $functionPayload = [
            // Function name plus our alias name.
            'FunctionName' => $function->nameWithPrefix() . ':active',

            // `RequestResponse` is a synchronous call, vs `Event` which
            // is a fire-and-forget, we can make it async by using the
            // invokeAsync method.
            'InvocationType' => 'RequestResponse',

            // Include the execution log in the response.
            'LogType' => 'Tail',

            // Pass the payload to the function.
            'Payload' => json_encode($payload)
        ];

        if (!$this->recording) {
            try {
                $result = app(LambdaClient::class)->{$method}($functionPayload);
            } catch (LambdaException $e) {
                if ($e->getStatusCode() === 404) {
                    throw FunctionNotFoundException::make($function);
                }
    
                throw $e;
            }
        } else {
            $this->recordedExecutions[] = [
                'function' => $function->nameWithPrefix(),
                'payload' => $payload
            ];

            $result = $this->mockExecution($method, $functionPayload);
        }

        // Let the calling function determine what to do with the result.
        $result = $function->toResult($result);

        event(new AfterFunctionExecuted($function, $payload, $result));

        $function->afterExecution($payload, $result);

        return $result;
    }

    /**
     * @param $function
     * @param array $payload
     * @return PendingResult|Results\SettledResult
     * @throws Exceptions\SidecarException
     */
    public function executeAsync($function, $payload = [])
    {
        return $this->execute($function, $payload, $async = true);
    }

    /**
     * @param $function
     * @param $payloads
     * @param bool $async
     * @return array
     * @throws Exceptions\SidecarException
     */
    public function executeMany($function, $payloads, $async = false)
    {
        if (is_int($payloads)) {
            $payloads = array_fill(0, $payloads, []);
        }

        $results = array_map(function ($payload) use ($function) {
            return $this->execute($function, $payload, $async = true);
        }, $payloads);

        if ($async) {
            // Return all the Pending Results.
            return $results;
        }

        // Wait for all the requests to finish.
        return array_map(function ($result) {
            return $result->settled();
        }, $results);
    }

    /**
     * @param $params
     * @return array
     * @throws Throwable
     */
    public function executeManyAsync($params)
    {
        return $this->executeMany($params, $async = true);
    }

    /**
     * Get an array of instantiated functions.
     *
     * @param null $functions
     * @return array
     */
    public function instantiatedFunctions($functions = null)
    {
        $functions = Arr::wrap($functions ?? config('sidecar.functions'));

        return array_map(function ($function) {
            return is_string($function) ? app($function) : $function;
        }, $functions);
    }

    /**
     * Warm functions by firing a set of async requests at them.
     *
     * @param null|array $functions
     */
    public function warm($functions = null)
    {
        $functions = $this->instantiatedFunctions($functions);

        collect($functions)->each(function ($function) {
            $config = $function->warmingConfig();

            if (!$config instanceof WarmingConfig || !$config->instances) {
                return;
            }

            $payloads = array_fill(0, $config->instances, $config->payload);

            $function::executeManyAsync($payloads);
        });
    }

    private function mockExecution($method, $functionPayload)
    {
        $mock = new MockHandler();
        $mock->append(new Result(['Payload' => json_encode($this->mockedResponse)]));

        /** @var LambdaClient */
        $client = app(LambdaClient::class);
        $command = $client->getCommand($method, $functionPayload);
        $command->getHandlerList()->setHandler($mock);

        return $client->execute($command);
    }
}
