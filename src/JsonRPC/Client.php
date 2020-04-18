<?php

namespace JsonRPC;

use BadFunctionCallException;
use InvalidArgumentException;
use RuntimeException;

use function json_encode;

class Client
{
    /**
     * URL of the server
     *
     * @var string
     */
    private $url;

    /**
     * If the only argument passed to a function is an array
     * assume it contains named arguments
     *
     * @var boolean
     */
    public $named_arguments = true;

    /**
     * HTTP client timeout
     *
     * @var integer
     */
    private $timeout;

    /**
     * Username for authentication
     *
     * @var string
     */
    private $username;

    /**
     * Password for authentication
     *
     * @var string
     */
    private $password;

    /**
     * True for a batch request
     *
     * @var boolean
     */
    public $is_batch = false;

    /**
     * Batch payload
     *
     * @var array
     */
    public $batch = [];

    /**
     * Enable debug output to the php error log
     *
     * @var boolean
     */
    public $debug = false;

    /**
     * Default HTTP headers to send to the server
     *
     * @var array
     */
    private $headers = [
        'User-Agent: JSON-RPC PHP Client <https://github.com/fguillot/JsonRPC>',
        'Content-Type: application/json',
        'Accept: application/json',
        'Connection: close',
    ];

    /**
     * SSL certificates verification
     *
     * @var boolean
     */
    public $ssl_verify_peer = true;

    /**
     * Constructor
     *
     * @param string $url Server URL
     * @param integer $timeout HTTP timeout
     * @param array $headers Custom HTTP headers
     */
    public function __construct($url, $timeout = 3, $headers = [])
    {
        $this->url = $url;
        $this->timeout = $timeout;
        $this->headers = array_merge($this->headers, $headers);
    }

    /**
     * Automatic mapping of procedures
     *
     * @param string $method Procedure name
     * @param array $params Procedure arguments
     * @return mixed
     * @throws ConnectionFailureException
     * @throws ResponseException
     */
    public function __call($method, array $params)
    {
        // Allow to pass an array and use named arguments
        if ($this->named_arguments && count($params) === 1 && is_array($params[0])) {
            $params = $params[0];
        }

        return $this->execute($method, $params);
    }

    /**
     * Set authentication parameters
     *
     * @param string $username Username
     * @param string $password Password
     * @return Client
     */
    public function authentication($username, $password): self
    {
        $this->username = $username;
        $this->password = $password;

        return $this;
    }

    /**
     * Start a batch request
     *
     * @return Client
     */
    public function batch(): self
    {
        $this->is_batch = true;
        $this->batch = [];

        return $this;
    }

    /**
     * Send a batch request
     *
     * @return array
     * @throws ConnectionFailureException
     * @throws ResponseException
     */
    public function send(): array
    {
        $this->is_batch = false;

        return $this->parseResponse(
            $this->doRequest($this->batch)
        );
    }

    /**
     * Execute a procedure
     *
     * @param string $procedure Procedure name
     * @param array $params Procedure arguments
     * @return mixed
     * @throws ConnectionFailureException
     * @throws ResponseException
     */
    public function execute($procedure, array $params = array())
    {
        if ($this->is_batch) {
            $this->batch[] = $this->prepareRequest($procedure, $params);
            return $this;
        }

        return $this->parseResponse(
            $this->doRequest($this->prepareRequest($procedure, $params))
        );
    }

    /**
     * Prepare the payload
     *
     * @param string $procedure Procedure name
     * @param array $params Procedure arguments
     * @return array
     */
    public function prepareRequest($procedure, array $params = array()): array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $procedure,
            'id' => mt_rand()
        ];

        if (!empty($params)) {
            $payload['params'] = $params;
        }

        return $payload;
    }

    /**
     * Parse the response and return the procedure result
     *
     * @param array $payload
     * @return mixed
     * @throws ResponseException
     */
    public function parseResponse(array $payload)
    {
        if ($this->isBatchResponse($payload)) {
            $results = [];

            foreach ($payload as $response) {
                $results[] = $this->getResult($response);
            }

            return $results;
        }

        return $this->getResult($payload);
    }

    /**
     * Throw an exception according the RPC error
     *
     * @param array $error
     * @throws BadFunctionCallException
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws ResponseException
     */
    public function handleRpcErrors(array $error): void
    {
        switch ($error['code']) {
            case -32700:
                throw new RuntimeException('Parse error: ' . $error['message']);
            case -32600:
                throw new RuntimeException('Invalid Request: ' . $error['message']);
            case -32601:
                throw new BadFunctionCallException('Procedure not found: ' . $error['message']);
            case -32602:
                throw new InvalidArgumentException('Invalid arguments: ' . $error['message']);
            default:
                throw new ResponseException(
                    $error['message'],
                    $error['code'],
                    null,
                    isset($error['data']) ? $error['data'] : null
                );
        }
    }

    /**
     * Throw an exception according the HTTP response
     *
     * @param array $headers
     */
    public function handleHttpErrors(array $headers): void
    {
        $exceptions = [
            401 => AccessDeniedException::class,
            403 => AccessDeniedException::class,
            404 => ConnectionFailureException::class,
            500 => ServerErrorException::class,
        ];

        foreach ($headers as $header) {
            foreach ($exceptions as $code => $exception) {
                if (strpos($header, 'HTTP/1.0 ' . $code) !== false || strpos($header, 'HTTP/1.1 ' . $code) !== false) {
                    throw new $exception('Response: ' . $header);
                }
            }
        }
    }

    /**
     * Do the HTTP request
     *
     * @param array $payload
     * @return array
     * @throws ConnectionFailureException
     */
    private function doRequest(array $payload): array
    {
        $stream = @fopen(trim($this->url), 'r', false, $this->getContext($payload));

        if (!is_resource($stream)) {
            throw new ConnectionFailureException('Unable to establish a connection');
        }

        $metadata = stream_get_meta_data($stream);
        $this->handleHttpErrors($metadata['wrapper_data']);

        $response = json_decode(stream_get_contents($stream), true);

        if ($this->debug) {
            error_log('==> Request: ' . PHP_EOL . json_encode($payload, JSON_PRETTY_PRINT));
            error_log('==> Response: ' . PHP_EOL . json_encode($response, JSON_PRETTY_PRINT));
        }

        return is_array($response) ? $response : [];
    }

    /**
     * Prepare stream context
     *
     * @param array $payload
     * @return resource
     */
    private function getContext(array $payload)
    {
        $headers = $this->headers;

        if (!empty($this->username) && !empty($this->password)) {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
        }

        return stream_context_create(
            [
                'http' => [
                    'method' => 'POST',
                    'protocol_version' => 1.1,
                    'timeout' => $this->timeout,
                    'max_redirects' => 2,
                    'header' => implode("\r\n", $headers),
                    'content' => json_encode($payload),
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => $this->ssl_verify_peer,
                    'verify_peer_name' => $this->ssl_verify_peer,
                ]
            ]
        );
    }

    /**
     * Return true if we have a batch response
     *
     * @param array $payload
     * @return boolean
     */
    private function isBatchResponse(array $payload): bool
    {
        return array_keys($payload) === range(0, count($payload) - 1);
    }

    /**
     * Get a RPC call result
     *
     * @param array $payload
     * @return mixed
     * @throws ResponseException
     */
    private function getResult(array $payload)
    {
        if (isset($payload['error']['code'])) {
            $this->handleRpcErrors($payload['error']);
        }

        return isset($payload['result']) ? $payload['result'] : null;
    }
}
