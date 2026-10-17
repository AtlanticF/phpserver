<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Protocol\TextRpc;

use PhpServer\Exception\ProtocolException;
use PhpServer\Protocol\TextRpc\ErrorBody;
use PhpServer\Protocol\TextRpc\ErrorCode;
use PhpServer\Protocol\TextRpc\JsonCodec;
use PHPUnit\Framework\TestCase;

final class JsonCodecTest extends TestCase
{
    private function codec(): JsonCodec
    {
        return new JsonCodec();
    }

    public function testRequestRoundTripIgnoresUnknownKeys(): void
    {
        $body = '{"jsonrpc":"2.0","method":"Echo.say","id":1,"params":["hi"],"extra":true}';
        $request = $this->codec()->decodeRequest($body);

        self::assertSame('Echo.say', $request->method);
        self::assertSame(['hi'], $request->params);
        self::assertSame(
            '{"method":"Echo.say","params":["hi"]}',
            $this->codec()->encodeRequest($request->method, $request->params),
        );
    }

    public function testOmittedParamsStayOmitted(): void
    {
        $request = $this->codec()->decodeRequest('{"method":"Echo.ping"}');

        self::assertNull($request->params);
        self::assertSame('{"method":"Echo.ping"}', $this->codec()->encodeRequest('Echo.ping', null));
    }

    public function testNamedParamsRoundTrip(): void
    {
        $request = $this->codec()->decodeRequest('{"method":"User.get","params":{"id":42}}');

        self::assertSame(['id' => 42], $request->params);
        self::assertSame(
            '{"method":"User.get","params":{"id":42}}',
            $this->codec()->encodeRequest('User.get', ['id' => 42]),
        );
    }

    public function testEmptyObjectParamsAreEncodedFromStdClass(): void
    {
        self::assertSame(
            '{"method":"Echo.say","params":{}}',
            $this->codec()->encodeRequest('Echo.say', new \stdClass()),
        );
    }

    public function testResultCanBeNull(): void
    {
        self::assertNull($this->codec()->decodeResult('{"result":null,"ignored":1}'));
        self::assertSame('{"result":null}', $this->codec()->encodeResult(null));
        self::assertSame(['id' => 42, 'name' => 'Mei'], $this->codec()->decodeResult('{"result":{"id":42,"name":"Mei"}}'));
    }

    public function testErrorWithAndWithoutData(): void
    {
        $with = $this->codec()->decodeError(
            '{"error":{"code":-32601,"message":"Method not found","data":{"method":"Echo.sya"}}}',
        );
        self::assertSame(-32601, $with->code);
        self::assertTrue($with->hasData);
        self::assertSame(['method' => 'Echo.sya'], $with->data);
        self::assertSame(
            '{"error":{"code":-32601,"message":"Method not found","data":{"method":"Echo.sya"}}}',
            $this->codec()->encodeError($with),
        );

        $without = $this->codec()->decodeError('{"error":{"code":1002,"message":"Insufficient balance"}}');
        self::assertFalse($without->hasData);
        self::assertNull($without->data);
        self::assertSame(
            '{"error":{"code":1002,"message":"Insufficient balance"}}',
            $this->codec()->encodeError(new ErrorBody(1002, 'Insufficient balance', null, false)),
        );
    }

    public function testInvalidUtf8IsABodyLevelParseError(): void
    {
        $this->assertBody(ErrorCode::ParseError, function (): void {
            $this->codec()->decodeRequest("\xFF");
        });
        $this->assertBody(ErrorCode::ParseError, function (): void {
            $this->codec()->decodeResult('{"result":"' . "\xFF" . '"}');
        });
    }

    public function testMalformedJsonIsABodyLevelParseError(): void
    {
        $this->assertBody(ErrorCode::ParseError, function (): void {
            $this->codec()->decodeRequest('{');
        });
        $this->assertBody(ErrorCode::ParseError, function (): void {
            $this->codec()->decodeError('');
        });
    }

    public function testWrongShapeIsInvalidRequestAndStaysOpen(): void
    {
        $this->assertBody(ErrorCode::InvalidRequest, function (): void {
            $this->codec()->decodeRequest('[]');
        });
        $this->assertBody(ErrorCode::InvalidRequest, function (): void {
            $this->codec()->decodeRequest('{"method":""}');
        });
        $this->assertBody(ErrorCode::InvalidRequest, function (): void {
            $this->codec()->decodeRequest('{"method":"Echo.say","params":"hi"}');
        });
        $this->assertBody(ErrorCode::InvalidRequest, function (): void {
            $this->codec()->decodeRequest('{"params":[1]}');
        });
        $this->assertBody(ErrorCode::InvalidRequest, function (): void {
            $this->codec()->decodeResult('{"value":1}');
        });
        $this->assertBody(ErrorCode::InvalidRequest, function (): void {
            $this->codec()->decodeError('{"error":{"code":1.5,"message":"no"}}');
        });
        $this->assertBody(ErrorCode::InvalidRequest, function (): void {
            $this->codec()->decodeError('{"error":{"code":1,"message":1}}');
        });
    }

    public function testDepthAboveTheLimitIsAParseError(): void
    {
        $shallow = $this->nested(40);
        self::assertIsArray($this->codec()->decodeResult('{"result":' . $shallow . '}'));

        $this->assertBody(ErrorCode::ParseError, function (): void {
            $this->codec()->decodeResult('{"result":' . $this->nested(80) . '}');
        });
    }

    private function nested(int $levels): string
    {
        $value = '0';
        for ($i = 0; $i < $levels; $i++) {
            $value = '[' . $value . ']';
        }

        return $value;
    }

    private function assertBody(ErrorCode $code, \Closure $run): void
    {
        try {
            $run();
            self::fail('expected ProtocolException');
        } catch (ProtocolException $exception) {
            self::assertSame($code->value, $exception->getCode());
            self::assertFalse($exception->isFrameLevel());
        }
    }
}
