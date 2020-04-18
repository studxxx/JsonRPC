<?php

use JsonRPC\ResponseEncodingFailure;
use JsonRPC\Server;
use PHPUnit\Framework\TestCase;

class C
{
    public function doSomething(): string
    {
        return 'something';
    }
}

class ServerProtocolTest extends TestCase
{
    /**
     * @throws ResponseEncodingFailure
     */
    public function testPositionalParameters(): void
    {
        $subtract = static function($minuend, $subtrahend) {
            return $minuend - $subtrahend;
        };

        $server = new Server('{"jsonrpc": "2.0", "method": "subtract", "params": [42, 23], "id": 1}');
        $server->register('subtract', $subtract);

        $this->assertEquals(
            json_decode('{"jsonrpc": "2.0", "result": 19, "id": 1}', true),
            json_decode($server->execute(), true)
        );

        $server = new Server('{"jsonrpc": "2.0", "method": "subtract", "params": [23, 42], "id": 1}');
        $server->register('subtract', $subtract);

        $this->assertEquals(
            json_decode('{"jsonrpc": "2.0", "result": -19, "id": 1}', true),
            json_decode($server->execute(), true)
        );
    }

    /**
     * @throws ResponseEncodingFailure
     */
    public function testNamedParameters(): void
    {
        $subtract = static function($minuend, $subtrahend) {
            return $minuend - $subtrahend;
        };

        $server = new Server('{"jsonrpc": "2.0", "method": "subtract", "params": {"subtrahend": 23, "minuend": 42}, "id": 3}');
        $server->register('subtract', $subtract);

        $this->assertEquals(
            json_decode('{"jsonrpc": "2.0", "result": 19, "id": 3}', true),
            json_decode($server->execute(), true)
        );

        $server = new Server('{"jsonrpc": "2.0", "method": "subtract", "params": {"minuend": 42, "subtrahend": 23}, "id": 4}');
        $server->register('subtract', $subtract);

        $this->assertEquals(
            json_decode('{"jsonrpc": "2.0", "result": 19, "id": 4}', true),
            json_decode($server->execute(), true)
        );
    }

    /**
     * @throws ResponseEncodingFailure
     */
    public function testNotification(): void
    {
        $update = static function($p1, $p2, $p3, $p4, $p5) {};
        $foobar = static function() {};


        $server = new Server('{"jsonrpc": "2.0", "method": "update", "params": [1,2,3,4,5]}');
        $server->register('update', $update);
        $server->register('foobar', $foobar);

        $this->assertEquals('', $server->execute());


        $server = new Server('{"jsonrpc": "2.0", "method": "foobar"}');
        $server->register('update', $update);
        $server->register('foobar', $foobar);

        $this->assertEquals('', $server->execute());
    }

    /**
     * @throws ResponseEncodingFailure
     */
    public function testNoMethod(): void
    {
        $server = new Server('{"jsonrpc": "2.0", "method": "foobar", "id": "1"}');

        $this->assertEquals(
            json_decode('{"jsonrpc": "2.0", "error": {"code": -32601, "message": "Method not found"}, "id": "1"}', true),
            json_decode($server->execute(), true)
        );
    }

    /**
     * @throws ResponseEncodingFailure
     */
    public function testInvalidJson(): void
    {
        $server = new Server('{"jsonrpc": "2.0", "method": "foobar, "params": "bar", "baz]');

        $this->assertEquals(
            json_decode('{"jsonrpc": "2.0", "error": {"code": -32700, "message": "Parse error"}, "id": null}', true),
            json_decode($server->execute(), true)
        );
    }

    /**
     * @throws ResponseEncodingFailure
     */
    public function testInvalidRequest(): void
    {
        $server = new Server('{"jsonrpc": "2.0", "method": 1, "params": "bar"}');

        $this->assertEquals(
            json_decode('{"jsonrpc": "2.0", "error": {"code": -32600, "message": "Invalid Request"}, "id": null}', true),
            json_decode($server->execute(), true)
        );
    }

    /**
     * @throws ResponseEncodingFailure
     */
    public function testInvalidResponse_MalformedCharacters(): void
    {
        $server = new Server('{"jsonrpc": "2.0", "method": "invalidresponse","id": 1}');

        $invalidresponse = static function() {
            return pack('H*', 'c32e');
        };

        $server->register('invalidresponse', $invalidresponse);

        $this->assertEquals(
            json_decode('{"jsonrpc": "2.0","id": 1, "error": {"code": -32603, "message": "Internal error","data": "Malformed UTF-8 characters, possibly incorrectly encoded"}}', true),
            json_decode($server->execute(), true)
        );
    }

    /**
     * @throws ResponseEncodingFailure
     */
    public function testBatchInvalidJson(): void
    {
        $server = new Server('[
          {"jsonrpc": "2.0", "method": "sum", "params": [1,2,4], "id": "1"},
          {"jsonrpc": "2.0", "method"
        ]');

        $this->assertEquals(
            json_decode('{"jsonrpc": "2.0", "error": {"code": -32700, "message": "Parse error"}, "id": null}', true),
            json_decode($server->execute(), true)
        );
    }

    /**
     * @throws ResponseEncodingFailure
     */
    public function testBatchEmptyArray(): void
    {
        $server = new Server('[]');

        $this->assertEquals(
            json_decode('{"jsonrpc": "2.0", "error": {"code": -32600, "message": "Invalid Request"}, "id": null}', true),
            json_decode($server->execute(), true)
        );
    }

    /**
     * @throws ResponseEncodingFailure
     */
    public function testBatchNotEmptyButInvalid(): void
    {
        $server = new Server('[1]');

        $this->assertEquals(
            json_decode('[{"jsonrpc": "2.0", "error": {"code": -32600, "message": "Invalid Request"}, "id": null}]', true),
            json_decode($server->execute(), true)
        );
    }

    /**
     * @throws ResponseEncodingFailure
     */
    public function testBatchInvalid(): void
    {
        $server = new Server('[1,2,3]');

        $this->assertEquals(
            json_decode('[
                {"jsonrpc": "2.0", "error": {"code": -32600, "message": "Invalid Request"}, "id": null},
                {"jsonrpc": "2.0", "error": {"code": -32600, "message": "Invalid Request"}, "id": null},
                {"jsonrpc": "2.0", "error": {"code": -32600, "message": "Invalid Request"}, "id": null}
            ]', true),
            json_decode($server->execute(), true)
        );
    }

    /**
     * @throws ResponseEncodingFailure
     */
    public function testBatchOk(): void
    {
        $server = new Server('[
            {"jsonrpc": "2.0", "method": "sum", "params": [1,2,4], "id": "1"},
            {"jsonrpc": "2.0", "method": "notify_hello", "params": [7]},
            {"jsonrpc": "2.0", "method": "subtract", "params": [42,23], "id": "2"},
            {"foo": "boo"},
            {"jsonrpc": "2.0", "method": "foo.get", "params": {"name": "myself"}, "id": "5"},
            {"jsonrpc": "2.0", "method": "get_data", "id": "9"},
            {"jsonrpc": "2.0", "method": "doSomething", "id": 10},
            {"jsonrpc": "2.0", "method": "doStuff", "id": 15}
        ]');

        $server->register('sum', static function($a, $b, $c) {
            return $a + $b + $c;
        });

        $server->register('subtract', static function($minuend, $subtrahend) {
            return $minuend - $subtrahend;
        });

        $server->register('get_data', static function() {
            return array('hello', 5);
        });

        $server->attach(new C);

        $server->bind('doStuff', 'C', 'doSomething');

        $response = $server->execute();

        $this->assertEquals(
            json_decode('[
                {"jsonrpc": "2.0", "result": 7, "id": "1"},
                {"jsonrpc": "2.0", "result": 19, "id": "2"},
                {"jsonrpc": "2.0", "error": {"code": -32600, "message": "Invalid Request"}, "id": null},
                {"jsonrpc": "2.0", "error": {"code": -32601, "message": "Method not found"}, "id": "5"},
                {"jsonrpc": "2.0", "result": ["hello", 5], "id": "9"},
                {"jsonrpc": "2.0", "result": "something", "id": "10"},
                {"jsonrpc": "2.0", "result": "something", "id": "15"}
            ]', true),
            json_decode($response, true)
        );
    }

    /**
     * @throws ResponseEncodingFailure
     */
    public function testBatchNotifications(): void
    {
        $server = new Server('[
            {"jsonrpc": "2.0", "method": "notify_sum", "params": [1,2,4]},
            {"jsonrpc": "2.0", "method": "notify_hello", "params": [7]}
        ]');

        $server->register('notify_sum', static function($a, $b, $c) {

        });

        $server->register('notify_hello', static function($id) {

        });

        $this->assertEquals('', $server->execute());
    }
}
