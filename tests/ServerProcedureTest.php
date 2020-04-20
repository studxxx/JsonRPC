<?php

use JsonRPC\Server;
use PHPUnit\Framework\TestCase;

class A
{
    public function getAll($p1, $p2, $p3 = 4): int
    {
        return $p1 + $p2 + $p3;
    }
}

class B
{
    public function getAll($p1): int
    {
        return $p1 + 2;
    }
}

class MyException extends RuntimeException {}

class ServerProcedureTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testProcedureNotFound(): void
    {
        $this->expectException(BadFunctionCallException::class);
        $server = new Server;
        $server->executeProcedure('a');
    }

    /**
     * @throws ReflectionException
     */
    public function testCallbackNotFound(): void
    {
        $this->expectException(BadFunctionCallException::class);
        $server = new Server;
        $server->register('b', static function() {});
        $server->executeProcedure('a');
    }

    /**
     * @throws ReflectionException
     */
    public function testClassNotFound(): void
    {
        $this->expectException(BadFunctionCallException::class);
        $server = new Server;
        $server->bind('getAllTasks', 'c', 'getAll');
        $server->executeProcedure('getAllTasks');
    }

    /**
     * @throws ReflectionException
     */
    public function testMethodNotFound(): void
    {
        $this->expectException(BadFunctionCallException::class);
        $server = new Server;
        $server->bind('getAllTasks', 'A', 'getNothing');
        $server->executeProcedure('getAllTasks');
    }

    public function testIsPositionalArguments(): void
    {
        $server = new Server;
        $this->assertFalse($server->isPositionalArguments(
            ['a' => 'b', 'c' => 'd'],
            ['a' => 'b', 'c' => 'd']
        ));

        $server = new Server;
        $this->assertTrue($server->isPositionalArguments(
            ['a', 'b', 'c'],
            ['a' => 'b', 'c' => 'd']
        ));
    }

    /**
     * @throws ReflectionException
     */
    public function testBindNamedArguments(): void
    {
        $server = new Server;
        $server->bind('getAllA', 'A', 'getAll');
        $server->bind('getAllB', 'B', 'getAll');
        $server->bind('getAllC', new B, 'getAll');
        $this->assertEquals(6, $server->executeProcedure('getAllA', ['p2' => 4, 'p1' => -2]));
        $this->assertEquals(10, $server->executeProcedure('getAllA', ['p2' => 4, 'p3' => 8, 'p1' => -2]));
        $this->assertEquals(6, $server->executeProcedure('getAllB', ['p1' => 4]));
        $this->assertEquals(5, $server->executeProcedure('getAllC', ['p1' => 3]));
    }

    /**
     * @throws ReflectionException
     */
    public function testBindPositionalArguments(): void
    {
        $server = new Server;
        $server->bind('getAllA', 'A', 'getAll');
        $server->bind('getAllB', 'B', 'getAll');
        $this->assertEquals(6, $server->executeProcedure('getAllA', [4, -2]));
        $this->assertEquals(2, $server->executeProcedure('getAllA', [4, 0, -2]));
        $this->assertEquals(4, $server->executeProcedure('getAllB', [2]));
    }

    /**
     * @throws ReflectionException
     */
    public function testRegisterNamedArguments(): void
    {
        $server = new Server;
        $server->register('getAllA', static function($p1, $p2, $p3 = 4) {
            return $p1 + $p2 + $p3;
        });

        $this->assertEquals(6, $server->executeProcedure('getAllA', ['p2' => 4, 'p1' => -2]));
        $this->assertEquals(10, $server->executeProcedure('getAllA', ['p2' => 4, 'p3' => 8, 'p1' => -2]));
    }

    /**
     * @throws ReflectionException
     */
    public function testRegisterPositionalArguments(): void
    {
        $server = new Server;
        $server->register('getAllA', static function($p1, $p2, $p3 = 4) {
            return $p1 + $p2 + $p3;
        });

        $this->assertEquals(6, $server->executeProcedure('getAllA', [4, -2]));
        $this->assertEquals(2, $server->executeProcedure('getAllA', [4, 0, -2]));
    }

    /**
     * @throws ReflectionException
     */
    public function testTooManyArguments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $server = new Server;
        $server->bind('getAllC', new B, 'getAll');
        $server->executeProcedure('getAllC', ['p1' => 3, 'p2' => 5]);
    }

    /**
     * @throws ReflectionException
     */
    public function testNotEnoughArguments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $server = new Server;
        $server->bind('getAllC', new B, 'getAll');
        $server->executeProcedure('getAllC');
    }

    public function testInvalidResponse(): void
    {
        $this->expectException(JsonRPC\ResponseEncodingFailure::class);
        $server = new Server;
        $server->getResponse(array(pack('H*', 'c32e')), ['id'=>1]);
    }

    /**
     * @throws ReflectionException
     */
    public function testAllowHosts(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $server = new Server();
        $server->allowHosts(['192.168.0.1', '127.0.0.1']);

        $server->register('sum', static function ($p1, $p2) {
            return $p1 + $p2;
        });

        $this->assertEquals(2, $server->executeProcedure('sum', [4, -2]));
    }
}
