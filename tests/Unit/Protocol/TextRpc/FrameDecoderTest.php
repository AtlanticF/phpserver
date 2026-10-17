<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Protocol\TextRpc;

use PhpServer\Exception\ProtocolException;
use PhpServer\Protocol\TextRpc\ErrorCode;
use PhpServer\Protocol\TextRpc\Frame;
use PhpServer\Protocol\TextRpc\FrameDecoder;
use PhpServer\Protocol\TextRpc\FrameEncoder;
use PhpServer\Protocol\TextRpc\FrameKind;
use PhpServer\Protocol\TextRpc\Header;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrameDecoderTest extends TestCase
{
    public function testByteAtATimeThenACompleteFrame(): void
    {
        $wire = "TRPC/1 REQ 1 json 4\nabcd\n";
        $decoder = new FrameDecoder();
        $last = strlen($wire) - 1;
        for ($index = 0; $index < $last; $index++) {
            $decoder->feed($wire[$index]);
            self::assertNotSame(0, $decoder->bytesNeeded());
            self::assertNull($decoder->next());
        }
        $decoder->feed($wire[$last]);
        self::assertSame(0, $decoder->bytesNeeded());
        $frame = $decoder->next();
        self::assertInstanceOf(Frame::class, $frame);
        self::assertSame(FrameKind::Req, $frame->header->kind);
        self::assertSame(1, $frame->header->id);
        self::assertSame('json', $frame->header->codec);
        self::assertSame('abcd', $frame->body);
        self::assertNull($frame->header->traceId);
        self::assertNull($decoder->next());
        self::assertNull($decoder->bytesNeeded());
    }

    public function testBodyLengthIsKnownAfterTheHeader(): void
    {
        $decoder = new FrameDecoder();
        $decoder->feed("TRPC/1 REQ 1 json 4\n");
        self::assertSame(5, $decoder->bytesNeeded());
        $decoder->feed('ab');
        self::assertSame(3, $decoder->bytesNeeded());
        $decoder->feed('cd');
        self::assertSame(1, $decoder->bytesNeeded());
        self::assertNull($decoder->next());
        $decoder->feed("\n");
        self::assertSame(0, $decoder->bytesNeeded());
        self::assertSame('abcd', $decoder->next()?->body);
    }

    public function testTwoFramesInOneBuffer(): void
    {
        $encoder = new FrameEncoder();
        $first = $this->frame(FrameKind::Req, 5, 'json', '{"method":"Echo.say","params":["a"]}', null);
        $second = $this->frame(FrameKind::Ping, 9, '-', '', null);
        $decoder = new FrameDecoder();
        $decoder->feed($encoder->encode($first) . $encoder->encode($second));

        self::assertEquals($first, $decoder->next());
        self::assertSame(0, $decoder->bytesNeeded());
        self::assertEquals($second, $decoder->next());
        self::assertNull($decoder->next());
    }

    public function testCrlfAndASplitCarriageReturn(): void
    {
        $decoder = new FrameDecoder();
        $decoder->feed("TRPC/1 REQ 2 json 4 web-1\r");
        self::assertNull($decoder->bytesNeeded());
        self::assertNull($decoder->next());
        $decoder->feed("\nabcd\r");
        self::assertSame(1, $decoder->bytesNeeded());
        self::assertNull($decoder->next());
        $decoder->feed("\n");
        $frame = $decoder->next();
        self::assertInstanceOf(Frame::class, $frame);
        self::assertSame('abcd', $frame->body);
        self::assertSame('web-1', $frame->header->traceId);
        self::assertSame([], $frame->header->meta);
    }

    public function testMixedLineEndingsOnAHeartbeat(): void
    {
        $decoder = new FrameDecoder();
        $decoder->feed("TRPC/1 PING 9 - 0\r\n\n");
        $frame = $decoder->next();
        self::assertInstanceOf(Frame::class, $frame);
        self::assertSame(FrameKind::Ping, $frame->header->kind);
        self::assertSame(9, $frame->header->id);
        self::assertSame('', $frame->body);
    }

    public function testExtensionFieldsAreKeptAndUnknownOnesDoNotMatter(): void
    {
        $decoder = new FrameDecoder();
        $decoder->feed("TRPC/1 REQ 12 json 2 web-9z x-prio=high x-a=b=c\nhi\n");
        $frame = $decoder->next();
        self::assertInstanceOf(Frame::class, $frame);
        self::assertSame('web-9z', $frame->header->traceId);
        self::assertSame(['x-prio' => 'high', 'x-a' => 'b=c'], $frame->header->meta);
        self::assertSame('hi', $frame->body);
    }

    public function testHeaderLimitIsEnforcedBeforeTheNewline(): void
    {
        $decoder = new FrameDecoder();
        $decoder->feed(str_repeat('A', 256));
        self::assertNull($decoder->bytesNeeded());
        $this->assertProtocol(ErrorCode::FrameTooLarge, true, function () use ($decoder): void {
            $decoder->feed('B');
        });
        $this->assertProtocol(ErrorCode::FrameTooLarge, true, function () use ($decoder): void {
            $decoder->feed("\n");
        });
    }

    public function testHeaderOf256BytesIsNotTooLarge(): void
    {
        $this->assertProtocol(ErrorCode::InvalidRequest, true, function (): void {
            $decoder = new FrameDecoder();
            $decoder->feed(str_repeat('A', 256) . "\n");
        });
    }

    public function testCrlfAfterA256ByteHeaderIsNotTooLarge(): void
    {
        $decoder = new FrameDecoder();
        $decoder->feed(str_repeat('A', 256) . "\r");
        self::assertNull($decoder->bytesNeeded());
        $this->assertProtocol(ErrorCode::InvalidRequest, true, function () use ($decoder): void {
            $decoder->feed("\n");
        });
    }

    public function testOverlongLenIsRejectedBeforeTheBodyIsRead(): void
    {
        $this->assertProtocol(ErrorCode::FrameTooLarge, true, function (): void {
            $decoder = new FrameDecoder();
            $decoder->feed("TRPC/1 REQ 10 json 1048577\n");
            $decoder->next();
        });
    }

    public function testOverlongLenInTheSameChunkDoesNotBecomeAFrame(): void
    {
        $decoder = new FrameDecoder();
        try {
            $decoder->feed("TRPC/1 REQ 10 json 1048577\nNOT-A-BODY");
            $decoder->next();
            self::fail('expected ProtocolException');
        } catch (ProtocolException $exception) {
            self::assertSame(ErrorCode::FrameTooLarge->value, $exception->getCode());
            self::assertTrue($exception->isFrameLevel());
        }
        self::assertSame('', $this->bodyOf($decoder));
    }

    public function testHardCapRejectsALargerConfiguration(): void
    {
        $decoder = new FrameDecoder(FrameDecoder::HARD_MAX_BODY_BYTES);
        $decoder->feed('TRPC/1 PING 1 - 0' . "\n\n");
        self::assertInstanceOf(Frame::class, $decoder->next());

        $this->expectException(\InvalidArgumentException::class);
        new FrameDecoder(FrameDecoder::HARD_MAX_BODY_BYTES + 1);
    }

    public function testLenInsideTheConfiguredMaxIsAccepted(): void
    {
        $decoder = new FrameDecoder(4);
        $decoder->feed("TRPC/1 REQ 1 json 4\nabcd\n");
        self::assertSame('abcd', $decoder->next()?->body);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badHeaders(): array
    {
        return [
            'hello' => ["HELLO\n"],
            'empty line' => ["\n"],
            'leading space' => [" TRPC/1 PING 1 - 0\n\n"],
            'double space' => ["TRPC/1  PING 1 - 0\n\n"],
            'lowercase kind' => ["TRPC/1 ping 1 - 0\n\n"],
            'leading zero id' => ["TRPC/1 PING 01 - 0\n\n"],
            'negative id' => ["TRPC/1 PING -1 - 0\n\n"],
            'id above int max' => ["TRPC/1 PING 9223372036854775808 - 0\n\n"],
            'leading zero len' => ["TRPC/1 REQ 1 json 01\n"],
            'negative len' => ["TRPC/1 REQ 1 json -1\n"],
            'len has too many digits' => ["TRPC/1 REQ 1 json 10000000000\n"],
            'uppercase codec' => ["TRPC/1 REQ 1 JSON 0\n\n"],
            'ping with json' => ["TRPC/1 PING 1 json 0\n\n"],
            'ping with a body length' => ["TRPC/1 PING 1 - 1\nx\n"],
            'request with empty codec' => ["TRPC/1 REQ 1 - 0\n\n"],
            'trace id too long' => ['TRPC/1 REQ 1 json 0 ' . str_repeat('a', 65) . "\n\n"],
            'trace id not first' => ["TRPC/1 REQ 1 json 0 x-a=b trace\n\n"],
            'duplicate extension' => ["TRPC/1 REQ 1 json 0 x-a=b x-a=c\n\n"],
            'uppercase extension key' => ["TRPC/1 REQ 1 json 0 X-prio=high\n\n"],
            'missing trailing newline' => ["TRPC/1 REQ 1 json 2\nhiX"],
        ];
    }

    #[DataProvider('badHeaders')]
    public function testBadHeadersAreFrameErrors(string $wire): void
    {
        $this->assertProtocol(ErrorCode::InvalidRequest, true, function () use ($wire): void {
            $decoder = new FrameDecoder();
            $decoder->feed($wire);
            $decoder->next();
        });
    }

    public function testUnknownVersionClosesAndIsNotParsedAsV1(): void
    {
        $this->assertProtocol(ErrorCode::UnsupportedVersionOrCodec, true, function (): void {
            $decoder = new FrameDecoder();
            $decoder->feed("TRPC/2 REQ 1 json 0\n\n");
        });
    }

    public function testBareUnknownVersionIsStillAVersionError(): void
    {
        $this->assertProtocol(ErrorCode::UnsupportedVersionOrCodec, true, function (): void {
            $decoder = new FrameDecoder();
            $decoder->feed("TRPC/2\n");
        });
    }

    public function testOpaqueBodyMayBeInvalidUtf8(): void
    {
        $decoder = new FrameDecoder();
        $decoder->feed("TRPC/1 REQ 1 json 1\n\xFF\n");
        $frame = $decoder->next();
        self::assertInstanceOf(Frame::class, $frame);
        self::assertSame("\xFF", $frame->body);
    }

    public function testMsgpackCodecIsAFrameTheCodecLayerCanRefuse(): void
    {
        $decoder = new FrameDecoder();
        $decoder->feed("TRPC/1 REQ 1 msgpack 3\n\x01\x02\x03\n");
        $frame = $decoder->next();
        self::assertInstanceOf(Frame::class, $frame);
        self::assertSame('msgpack', $frame->header->codec);
        self::assertSame("\x01\x02\x03", $frame->body);
    }

    public function testMaxIntIdRoundTrips(): void
    {
        $id = PHP_INT_MAX;
        $decoder = new FrameDecoder();
        $decoder->feed('TRPC/1 PING ' . $id . " - 0\n\n");
        self::assertSame($id, $decoder->next()?->header->id);
    }

    private function frame(FrameKind $kind, int $id, string $codec, string $body, ?string $traceId): Frame
    {
        return new Frame(new Header($kind, $id, $codec, strlen($body), $traceId, []), $body);
    }

    private function assertProtocol(ErrorCode $code, bool $frameLevel, \Closure $run): void
    {
        try {
            $run();
            self::fail('expected ProtocolException');
        } catch (ProtocolException $exception) {
            self::assertSame($code->value, $exception->getCode());
            self::assertSame($code, ErrorCode::from($exception->getCode()));
            self::assertSame($frameLevel, $exception->isFrameLevel());
        }
    }

    private function bodyOf(FrameDecoder $decoder): ?string
    {
        $property = new \ReflectionProperty(FrameDecoder::class, 'body');

        $value = $property->getValue($decoder);

        return is_string($value) ? $value : null;
    }
}
