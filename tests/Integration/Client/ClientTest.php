<?php

declare(strict_types=1);

namespace PhpServer\Tests\Integration\Client;

use PhpServer\Client\Client;
use PhpServer\Client\NetworkException;
use PhpServer\Client\RpcError;
use PhpServer\Exception\ProtocolException;
use PhpServer\Protocol\TextRpc\ErrorCode;
use PhpServer\Tests\Support\TextRpcFixtureProcess;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testEchoReturnsTheResultAndTraceId(): void
    {
        $fixture = TextRpcFixtureProcess::start('echo');
        $client = new Client();
        try {
            $seen = [];
            $client->onWire(function (string $direction, string $bytes) use (&$seen): void {
                $seen[] = $direction . ':' . $bytes;
            });
            $client->connect($fixture->address, 2.0);
            $response = $client->call('Echo.say', ['hi'], 'web-1a2b3c');

            $wire = implode("\n", $seen);
            self::assertSame('hi', $response->result);
            self::assertSame(1, $response->id);
            self::assertSame('web-1a2b3c', $response->traceId);
            self::assertStringContainsString('web-1a2b3c', $wire);
            self::assertStringContainsString('send:TRPC/1 REQ 1 json', $wire);
            self::assertStringContainsString('recv:TRPC/1 RES 1 json', $wire);
        } finally {
            $client->close();
            $fixture->stop();
        }
    }

    public function testSequentialCallsDoNotPipeline(): void
    {
        $fixture = TextRpcFixtureProcess::start('echo');
        $client = new Client();
        try {
            $client->connect($fixture->address, 2.0);
            $first = $client->call('Echo.say', ['a']);
            $second = $client->call('Echo.say', ['b']);

            self::assertSame('a', $first->result);
            self::assertSame('b', $second->result);
            self::assertSame(1, $first->id);
            self::assertSame(2, $second->id);
            self::assertNotSame('', $first->traceId);
        } finally {
            $client->close();
            $fixture->stop();
        }
    }

    public function testErrorReplyKeepsTheConnectionOpen(): void
    {
        $fixture = TextRpcFixtureProcess::start('once-error');
        $client = new Client();
        try {
            $client->connect($fixture->address, 2.0);
            try {
                $client->call('Echo.sya', ['hi']);
                self::fail('expected RpcError');
            } catch (RpcError $error) {
                self::assertSame(ErrorCode::MethodNotFound->value, $error->getCode());
                self::assertSame('Method not found', $error->getMessage());
                self::assertTrue($error->hasData);
                self::assertSame(['method' => 'Echo.sya'], $error->data);
                self::assertNotSame('', $error->traceId);
            }
            self::assertSame('hi', $client->call('Echo.say', ['hi'])->result);
        } finally {
            $client->close();
            $fixture->stop();
        }
    }

    public function testBadFrameClosesTheConnection(): void
    {
        $fixture = TextRpcFixtureProcess::start('bad-frame');
        $client = new Client();
        try {
            $client->connect($fixture->address, 2.0);
            try {
                $client->call('Echo.say', ['hi']);
                self::fail('expected ProtocolException');
            } catch (ProtocolException $exception) {
                self::assertSame(ErrorCode::InvalidRequest->value, $exception->getCode());
                self::assertTrue($exception->isFrameLevel());
            }
            $this->expectException(\LogicException::class);
            $client->call('Echo.say', ['hi']);
        } finally {
            $client->close();
            $fixture->stop();
        }
    }

    public function testShuttingDownClosesTheConnection(): void
    {
        $fixture = TextRpcFixtureProcess::start('shutdown');
        $client = new Client();
        try {
            $client->connect($fixture->address, 2.0);
            try {
                $client->call('Echo.say', ['x']);
                self::fail('expected RpcError');
            } catch (RpcError $error) {
                self::assertSame(ErrorCode::ShuttingDown->value, $error->getCode());
            }
            $this->expectException(\LogicException::class);
            $client->ping();
        } finally {
            $client->close();
            $fixture->stop();
        }
    }

    public function testSlowPeerTimesOut(): void
    {
        $fixture = TextRpcFixtureProcess::start('slow');
        $client = new Client();
        try {
            $client->connect($fixture->address, 2.0);
            $this->expectException(NetworkException::class);
            $client->call('Echo.say', ['hi'], null, 200);
        } finally {
            $client->close();
            $fixture->stop();
        }
    }

    public function testPing(): void
    {
        $fixture = TextRpcFixtureProcess::start('echo');
        $client = new Client();
        try {
            $client->connect($fixture->address, 2.0);
            $client->ping(1000, 'web-ping');
            $response = $client->call('Echo.say', ['z']);
            self::assertSame(2, $response->id);
            self::assertSame('z', $response->result);
        } finally {
            $client->close();
            $fixture->stop();
        }
    }

    public function testNamedParams(): void
    {
        $fixture = TextRpcFixtureProcess::start('echo');
        $client = new Client();
        try {
            $client->connect($fixture->address, 2.0);
            $response = $client->call('User.get', ['id' => 42]);

            self::assertSame(['id' => 42], $response->result);
        } finally {
            $client->close();
            $fixture->stop();
        }
    }

    public function testCallBeforeConnectIsRejected(): void
    {
        $client = new Client();
        $this->expectException(\LogicException::class);
        $client->call('Echo.say', ['hi']);
    }

    public function testBadAddressIsRejected(): void
    {
        $client = new Client();
        $this->expectException(\InvalidArgumentException::class);
        $client->connect('not a port', 1.0);
    }
}
