<?php

use JsonRPC\AccessDeniedException;
use JsonRPC\Client;
use JsonRPC\ConnectionFailureException;
use JsonRPC\ResponseException;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    /**
     * @throws ResponseException
     */
    public function testParseResponse(): void
    {
        $client = new Client('http://localhost/');

        $this->assertEquals(
            -19,
            $client->parseResponse(json_decode('{"jsonrpc": "2.0", "result": -19, "id": 1}', true))
        );

        $this->assertEquals(
            null,
            $client->parseResponse(json_decode('{"jsonrpc": "2.0", "id": 1}', true))
        );
    }

    /**
     * @throws ResponseException
     */
    public function testBadProcedure(): void
    {
        $this->expectException(BadFunctionCallException::class);
        $client = new Client('http://localhost/');
        $client->parseResponse(json_decode('{"jsonrpc": "2.0", "error": {"code": -32601, "message": "Method not found"}, "id": "1"}', true));
    }

    /**
     * @throws ResponseException
     */
    public function testInvalidArgs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $client = new Client('http://localhost/');
        $client->parseResponse(json_decode('{"jsonrpc": "2.0", "error": {"code": -32602, "message": "Invalid params"}, "id": "1"}', true));
    }

    /**
     * @throws ResponseException
     */
    public function testInvalidRequest(): void
    {
        $this->expectException(RuntimeException::class);
        $client = new Client('http://localhost/');
        $client->parseResponse(json_decode('{"jsonrpc": "2.0", "error": {"code": -32600, "message": "Invalid Request"}, "id": null}', true));
    }

    /**
     * @throws ResponseException
     */
    public function testParseError(): void
    {
        $this->expectException(RuntimeException::class);
        $client = new Client('http://localhost/');
        $client->parseResponse(json_decode('{"jsonrpc": "2.0", "error": {"code": -32700, "message": "Parse error"}, "id": null}', true));
    }

    public function testServerError(): void
    {
        $this->expectException(JsonRPC\ServerErrorException::class);
        $client = new Client('http://localhost/');
        $client->handleHttpErrors(array('HTTP/1.0 301 Moved Permantenly', 'Connection: close', 'HTTP/1.1 500 Internal Server Error'));
    }

    /**
     * @throws ResponseException
     * @throws ConnectionFailureException
     */
    public function testBadUrl(): void
    {
        $this->expectException(ConnectionFailureException::class);
        $client = new Client('http://something_not_found/', 1);
        $client->execute('plop');
    }

    public function test404(): void
    {
        $this->expectException(ConnectionFailureException::class);
        $client = new Client('http://localhost/');
        $client->handleHttpErrors(array('HTTP/1.1 404 Not Found'));
    }

    public function testAccessForbiddenError(): void
    {
        $this->expectException(AccessDeniedException::class);
        $client = new Client('http://localhost/');
        $client->handleHttpErrors(array('HTTP/1.0 301 Moved Permanently', 'Connection: close', 'HTTP/1.1 403 Forbidden'));
    }

    public function testAccessNotAllowedError(): void
    {
        $this->expectException(AccessDeniedException::class);
        $client = new Client('http://localhost/');
        $client->handleHttpErrors(array('HTTP/1.0 301 Moved Permanently', 'Connection: close', 'HTTP/1.0 401 Unauthorized'));
    }

    public function testPrepareRequest(): void
    {
        $client = new Client('http://localhost/');

        $payload = $client->prepareRequest('myProcedure');
        $this->assertNotEmpty($payload);
        $this->assertArrayHasKey('jsonrpc', $payload);
        $this->assertEquals('2.0', $payload['jsonrpc']);
        $this->assertArrayHasKey('method', $payload);
        $this->assertEquals('myProcedure', $payload['method']);
        $this->assertArrayHasKey('id', $payload);
        $this->assertArrayNotHasKey('params', $payload);

        $payload = $client->prepareRequest('myProcedure', array('p1' => 3));
        $this->assertNotEmpty($payload);
        $this->assertArrayHasKey('jsonrpc', $payload);
        $this->assertEquals('2.0', $payload['jsonrpc']);
        $this->assertArrayHasKey('method', $payload);
        $this->assertEquals('myProcedure', $payload['method']);
        $this->assertArrayHasKey('id', $payload);
        $this->assertArrayHasKey('params', $payload);
        $this->assertEquals(array('p1' => 3), $payload['params']);
    }

    /**
     * @throws ResponseException
     * @throws ConnectionFailureException
     */
    public function testBatchRequest(): void
    {
        $client = new Client('http://localhost/');

        $batch = $client->batch();

        $this->assertInstanceOf('JsonRpc\Client', $batch);
        $this->assertTrue($client->is_batch);

        $batch->random(1, 30);
        $batch->add(3, 5);
        $batch->execute('foo', array('p1' => 42, 'p3' => 3));

        $this->assertNotEmpty($client->batch);
        $this->assertCount(3, $client->batch);

        $this->assertEquals('random', $client->batch[0]['method']);
        $this->assertEquals('add', $client->batch[1]['method']);
        $this->assertEquals('foo', $client->batch[2]['method']);

        $this->assertEquals(array(1, 30), $client->batch[0]['params']);
        $this->assertEquals(array(3, 5), $client->batch[1]['params']);
        $this->assertEquals(array('p1' => 42, 'p3' => 3), $client->batch[2]['params']);

        $batch = $client->batch();

        $this->assertInstanceOf('JsonRpc\Client', $batch);
        $this->assertTrue($client->is_batch);
        $this->assertEmpty($client->batch);
    }
}
