<?php

namespace JsonRPC;

use Closure;
use BadFunctionCallException;
use Exception;
use InvalidArgumentException;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

class Server
{
    /**
     * Data received from the client
     *
     * @var array
     */
    private $payload;

    /**
     * List of procedures
     *
     * @var array
     */
    private $callbacks = [];

    /**
     * List of classes
     *
     * @var array
     */
    private $classes = [];

    /**
     * List of instances
     *
     * @var array
     */
    private $instances = [];

    /**
     * List of exception classes that should be relayed to client
     *
     * @var array
     */
    private $exceptions = [];

    /**
     * Method name to execute before the procedure
     *
     * @var string
     */
    private $before = '';

    /**
     * Username
     *
     * @var string
     */
    private $username = '';

    /**
     * Password
     *
     * @var string
     */
    private $password = '';

    /**
     * Constructor
     *
     * @param string $request
     */
    public function __construct(?string $request = null)
    {
        $this->payload = json_decode($request ?? file_get_contents('php://input'), true);
    }

    /**
     * Set a payload
     *
     * @param array $payload
     * @return Server
     */
    public function setPayload(array $payload): self
    {
        $this->payload = $payload;
        return $this;
    }

    /**
     * Define alternative authentication header
     *
     * @param string $header Header name
     * @return Server
     */
    public function setAuthenticationHeader($header): self
    {
        if (!empty($header)) {
            $header = 'HTTP_' . str_replace('-', '_', strtoupper($header));

            if (isset($_SERVER[$header])) {
                [$this->username, $this->password] = explode(':', @base64_decode($_SERVER[$header]));
            }
        }

        return $this;
    }

    /**
     * Get username
     *
     * @return string
     */
    public function getUsername(): ?string
    {
        return $this->username ?: @$_SERVER['PHP_AUTH_USER'];
    }

    /**
     * Get password
     *
     * @return string
     */
    public function getPassword(): ?string
    {
        return $this->password ?: @$_SERVER['PHP_AUTH_PW'];
    }

    /**
     * Send authentication failure response
     */
    public function sendAuthenticationFailureResponse(): void
    {
        header('WWW-Authenticate: Basic realm="JsonRPC"');
        header('Content-Type: application/json');
        header('HTTP/1.0 401 Unauthorized');
        echo '{"error": "Authentication failed"}';
        exit;
    }

    /**
     * Send forbidden response
     */
    public function sendForbiddenResponse(): void
    {
        header('Content-Type: application/json');
        header('HTTP/1.0 403 Forbidden');
        echo '{"error": "Access Forbidden"}';
        exit;
    }

    /**
     * IP based client restrictions
     * Return an HTTP error 403 if the client is not allowed
     *
     * @param array $hosts List of hosts
     */
    public function allowHosts(array $hosts): void
    {
        if (!in_array($_SERVER['REMOTE_ADDR'], $hosts, true)) {
            $this->sendForbiddenResponse();
        }
    }

    /**
     * HTTP Basic authentication
     * Return an HTTP error 401 if the client is not allowed
     *
     * @param array $users Map of username/password
     * @return Server
     */
    public function authentication(array $users): self
    {
        if (!isset($users[$this->getUsername()]) || $users[$this->getUsername()] !== $this->getPassword()) {
            $this->sendAuthenticationFailureResponse();
        }

        return $this;
    }

    /**
     * Register a new procedure
     *
     * @param string $procedure Procedure name
     * @param closure $callback Callback
     * @return Server
     */
    public function register($procedure, Closure $callback): self
    {
        $this->callbacks[$procedure] = $callback;
        return $this;
    }

    /**
     * Bind a procedure to a class
     *
     * @param string $procedure Procedure name
     * @param mixed $class Class name or instance
     * @param string $method Procedure name
     * @return Server
     */
    public function bind($procedure, $class, $method = ''): self
    {
        if ($method === '') {
            $method = $procedure;
        }

        $this->classes[$procedure] = [$class, $method];
        return $this;
    }

    /**
     * Bind a class instance
     *
     * @param mixed $instance Instance name
     * @return Server
     */
    public function attach($instance): self
    {
        $this->instances[] = $instance;
        return $this;
    }

    /**
     * Bind an exception
     * If this exception occurs it is relayed to the client as JSON-RPC error
     *
     * @param mixed $exception Exception class. Defaults to all.
     * @return Server
     */
    public function attachException($exception = 'Exception'): self
    {
        $this->exceptions[] = $exception;
        return $this;
    }

    /**
     * Attach a method that will be called before the procedure
     *
     * @param string $before
     * @return Server
     */
    public function before($before): self
    {
        $this->before = $before;
        return $this;
    }

    /**
     * Return the response to the client
     *
     * @param array $data Data to send to the client
     * @param array $payload Incoming data
     * @return string
     * @throws ResponseEncodingFailure
     */
    public function getResponse(array $data, array $payload = []): string
    {
        if (!array_key_exists('id', $payload)) {
            return '';
        }

        $response = [
            'jsonrpc' => '2.0',
            'id' => $payload['id']
        ];

        $response = array_merge($response, $data);

        @header('Content-Type: application/json');

        $encodedResponse = json_encode($response);
        $jsonError = json_last_error();
        if ($jsonError !== JSON_ERROR_NONE) {
            switch ($jsonError) {
                case JSON_ERROR_NONE:
                    $errorMessage = 'No errors';
                    break;
                case JSON_ERROR_DEPTH:
                    $errorMessage = 'Maximum stack depth exceeded';
                    break;
                case JSON_ERROR_STATE_MISMATCH:
                    $errorMessage = 'Underflow or the modes mismatch';
                    break;
                case JSON_ERROR_CTRL_CHAR:
                    $errorMessage = 'Unexpected control character found';
                    break;
                case JSON_ERROR_SYNTAX:
                    $errorMessage = 'Syntax error, malformed JSON';
                    break;
                case JSON_ERROR_UTF8:
                    $errorMessage = 'Malformed UTF-8 characters, possibly incorrectly encoded';
                    break;
                default:
                    $errorMessage = 'Unknown error';
                    break;
            }
            throw new ResponseEncodingFailure($errorMessage, $jsonError);
        }
        return $encodedResponse;
    }

    /**
     * Parse the payload and test if the parsed JSON is ok
     *
     * @throws InvalidJsonFormat
     */
    private function checkJsonFormat(): void
    {
        if (!is_array($this->payload)) {
            throw new InvalidJsonFormat('Malformed payload');
        }
    }

    /**
     * Test if all required JSON-RPC parameters are here
     *
     * @throws InvalidJsonRpcFormat
     */
    private function checkRpcFormat(): void
    {
        if (!isset($this->payload['jsonrpc']) ||
            !isset($this->payload['method']) ||
            !is_string($this->payload['method']) ||
            $this->payload['jsonrpc'] !== '2.0' ||
            (isset($this->payload['params']) && !is_array($this->payload['params']))
        ) {
            throw new InvalidJsonRpcFormat('Invalid JSON RPC payload');
        }
    }

    /**
     * Return true if we have a batch request
     *
     * @return boolean
     */
    private function isBatchRequest(): bool
    {
        return array_keys($this->payload) === range(0, count($this->payload) - 1);
    }

    /**
     * Handle batch request
     *
     * @return string
     * @throws ResponseEncodingFailure
     */
    private function handleBatchRequest(): string
    {
        $responses = [];

        foreach ($this->payload as $payload) {
            if (!is_array($payload)) {
                $responses[] = $this->getResponse(
                    [
                        'error' => [
                            'code' => -32600,
                            'message' => 'Invalid Request'
                        ]
                    ],
                    ['id' => null]
                );
            } else {
                $server = clone($this);
                $server->setPayload($payload);
                $response = $server->execute();

                if (!empty($response)) {
                    $responses[] = $response;
                }
            }
        }

        return empty($responses) ? '' : '[' . implode(',', $responses) . ']';
    }

    /**
     * Parse incoming requests
     *
     * @return string
     * @throws ResponseEncodingFailure
     * @throws Exception
     */
    public function execute(): string
    {
        try {
            $this->checkJsonFormat();

            if ($this->isBatchRequest()) {
                return $this->handleBatchRequest();
            }

            $this->checkRpcFormat();

            $result = $this->executeProcedure(
                $this->payload['method'],
                empty($this->payload['params']) ? [] : $this->payload['params']
            );

            return $this->getResponse(['result' => $result], $this->payload);
        } catch (InvalidJsonFormat $e) {
            return $this->getResponse(
                [
                    'error' => [
                        'code' => -32700,
                        'message' => 'Parse error'
                    ]
                ],
                ['id' => null]
            );
        } catch (InvalidJsonRpcFormat $e) {
            return $this->getResponse(
                [
                    'error' => [
                        'code' => -32600,
                        'message' => 'Invalid Request'
                    ]
                ],
                ['id' => null]
            );
        } catch (BadFunctionCallException $e) {
            return $this->getResponse(
                [
                    'error' => [
                        'code' => -32601,
                        'message' => 'Method not found'
                    ]
                ],
                $this->payload
            );
        } catch (InvalidArgumentException $e) {
            return $this->getResponse(
                [
                    'error' => [
                        'code' => -32602,
                        'message' => 'Invalid params'
                    ]
                ],
                $this->payload
            );
        } catch (ResponseEncodingFailure $e) {
            return $this->getResponse(
                [
                    'error' => [
                        'code' => -32603,
                        'message' => 'Internal error',
                        'data' => $e->getMessage()
                    ]
                ],
                $this->payload
            );
        } catch (AuthenticationFailure $e) {
            $this->sendAuthenticationFailureResponse();
        } catch (AccessDeniedException $e) {
            $this->sendForbiddenResponse();
        } catch (ResponseException $e) {
            return $this->getResponse(
                [
                    'error' => [
                        'code' => $e->getCode(),
                        'message' => $e->getMessage(),
                        'data' => $e->getData(),
                    ]
                ],
                $this->payload
            );
        } catch (Exception $e) {
            foreach ($this->exceptions as $class) {
                if ($e instanceof $class) {
                    return $this->getResponse(
                        [
                            'error' => [
                                'code' => $e->getCode(),
                                'message' => $e->getMessage()
                            ]
                        ],
                        $this->payload
                    );
                }
            }
            throw $e;
        }
    }

    /**
     * Execute the procedure
     *
     * @param string $procedure Procedure name
     * @param array $params Procedure params
     * @return mixed
     * @throws ReflectionException
     */
    public function executeProcedure($procedure, array $params = [])
    {
        if (isset($this->callbacks[$procedure])) {
            return $this->executeCallback($this->callbacks[$procedure], $params);
        }

        if (isset($this->classes[$procedure]) && method_exists(
                $this->classes[$procedure][0],
                $this->classes[$procedure][1]
            )) {
            return $this->executeMethod($this->classes[$procedure][0], $this->classes[$procedure][1], $params);
        }

        foreach ($this->instances as $instance) {
            if (method_exists($instance, $procedure)) {
                return $this->executeMethod($instance, $procedure, $params);
            }
        }

        throw new BadFunctionCallException('Unable to find the procedure');
    }

    /**
     * Execute a callback
     *
     * @param Closure $callback Callback
     * @param array $params Procedure params
     * @return mixed
     * @throws ReflectionException
     */
    public function executeCallback(Closure $callback, $params)
    {
        $reflection = new ReflectionFunction($callback);

        $arguments = $this->getArguments(
            $params,
            $reflection->getParameters(),
            $reflection->getNumberOfRequiredParameters(),
            $reflection->getNumberOfParameters()
        );

        return $reflection->invokeArgs($arguments);
    }

    /**
     * Execute a method
     *
     * @param mixed $class Class name or instance
     * @param string $method Method name
     * @param array $params Procedure params
     * @return mixed
     * @throws ReflectionException
     */
    public function executeMethod($class, $method, $params)
    {
        $instance = is_string($class) ? new $class : $class;

        // Execute before action
        if (!empty($this->before)) {
            if (is_callable($this->before)) {
                call_user_func($this->before, $this->getUsername(), $this->getPassword(), get_class($class), $method);
            } elseif (method_exists($instance, $this->before)) {
                $instance->{$this->before}($this->getUsername(), $this->getPassword(), get_class($class), $method);
            }
        }

        $reflection = new ReflectionMethod($class, $method);

        $arguments = $this->getArguments(
            $params,
            $reflection->getParameters(),
            $reflection->getNumberOfRequiredParameters(),
            $reflection->getNumberOfParameters()
        );

        return $reflection->invokeArgs($instance, $arguments);
    }

    /**
     * Get procedure arguments
     *
     * @param array $request_params Incoming arguments
     * @param array $method_params Procedure arguments
     * @param integer $nb_required_params Number of required parameters
     * @param integer $nb_max_params Maximum number of parameters
     * @return array
     */
    public function getArguments(
        array $request_params,
        array $method_params,
        $nb_required_params,
        $nb_max_params
    ): array {
        $nb_params = count($request_params);

        if ($nb_params < $nb_required_params) {
            throw new InvalidArgumentException('Wrong number of arguments');
        }

        if ($nb_params > $nb_max_params) {
            throw new InvalidArgumentException('Too many arguments');
        }

        if ($this->isPositionalArguments($request_params, $method_params)) {
            return $request_params;
        }

        return $this->getNamedArguments($request_params, $method_params);
    }

    /**
     * Return true if we have positional parametes
     *
     * @param array $request_params Incoming arguments
     * @param array $method_params Procedure arguments
     * @return bool
     */
    public function isPositionalArguments(array $request_params, array $method_params): bool
    {
        return array_keys($request_params) === range(0, count($request_params) - 1);
    }

    /**
     * Get named arguments
     *
     * @param array $request_params Incoming arguments
     * @param array $method_params Procedure arguments
     * @return array
     */
    public function getNamedArguments(array $request_params, array $method_params): array
    {
        $params = [];

        foreach ($method_params as $p) {
            $name = $p->getName();

            if (isset($request_params[$name])) {
                $params[$name] = $request_params[$name];
            } elseif ($p->isDefaultValueAvailable()) {
                $params[$name] = $p->getDefaultValue();
            } else {
                throw new InvalidArgumentException('Missing argument: ' . $name);
            }
        }

        return $params;
    }
}
