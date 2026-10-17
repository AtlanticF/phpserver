<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Protocol\TextRpc;

use PhpServer\Exception\ProtocolException;
use PhpServer\Protocol\TextRpc\ErrorCode;
use PhpServer\Protocol\TextRpc\Frame;
use PhpServer\Protocol\TextRpc\FrameDecoder;
use PhpServer\Protocol\TextRpc\FrameEncoder;
use PhpServer\Protocol\TextRpc\FrameKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpecExampleTest extends TestCase
{
    public function testSpecDocumentIsTheApprovedText(): void
    {
        $spec = file_get_contents(dirname(__DIR__, 4) . '/docs/protocol-text-rpc.md');
        self::assertIsString($spec);
        self::assertStringContainsString('TRPC/1', $spec);
        self::assertStringContainsString('bin/phpserver-rpc 127.0.0.1:9000 Echo.say', $spec);
        self::assertStringContainsString('{"method":"Echo.say","params":["hi"]}', $spec);
    }

    #[DataProvider('examples')]
    public function testDecoderAcceptsTheSpecBytes(string $header, string $body, FrameKind $kind, int $id, ?string $traceId): void
    {
        $wire = $this->wire($header, $body);
        $decoder = new FrameDecoder();
        $decoder->feed($wire);
        $frame = $decoder->next();
        self::assertNotNull($frame);
        self::assertSame($kind, $frame->header->kind);
        self::assertSame($id, $frame->header->id);
        self::assertSame($body, $frame->body);
        self::assertSame($traceId, $frame->header->traceId);
        self::assertNull($decoder->next());

        $again = new FrameDecoder();
        $again->feed(str_replace("\n", "\r\n", $wire));
        self::assertEquals($frame, $again->next());

        self::assertSame($wire, (new FrameEncoder())->encode($frame));
    }

    public function testExample1FeedsOneByteAtATime(): void
    {
        $wire = $this->wire('TRPC/1 REQ 1 json 37', '{"method":"Echo.say","params":["hi"]}');
        $decoder = new FrameDecoder();
        for ($index = 0, $last = strlen($wire) - 1; $index < $last; $index++) {
            $decoder->feed($wire[$index]);
            self::assertNull($decoder->next());
        }
        $decoder->feed($wire[$last]);
        self::assertSame('{"method":"Echo.say","params":["hi"]}', $decoder->next()?->body);
    }

    public function testExample5PipelinesBothRequestsInOneBuffer(): void
    {
        $wire = $this->wire('TRPC/1 REQ 5 json 36', '{"method":"Echo.say","params":["a"]}')
            . $this->wire('TRPC/1 REQ 6 json 36', '{"method":"Echo.say","params":["b"]}');
        $decoder = new FrameDecoder();
        $decoder->feed($wire);
        $first = $decoder->next();
        $second = $decoder->next();
        self::assertInstanceOf(Frame::class, $first);
        self::assertInstanceOf(Frame::class, $second);
        self::assertSame(5, $first->header->id);
        self::assertSame(6, $second->header->id);
        self::assertNull($decoder->next());
    }

    public function testExample7RejectsTheOversizedHeaderWithoutABody(): void
    {
        $decoder = new FrameDecoder();
        try {
            $decoder->feed("TRPC/1 REQ 10 json 2000000\n");
            self::fail('expected ProtocolException');
        } catch (ProtocolException $exception) {
            self::assertSame(ErrorCode::FrameTooLarge->value, $exception->getCode());
            self::assertTrue($exception->isFrameLevel());
        }
    }

    public function testExample8RejectsAnUnparsedHeader(): void
    {
        $decoder = new FrameDecoder();
        try {
            $decoder->feed("HELLO\n");
            self::fail('expected ProtocolException');
        } catch (ProtocolException $exception) {
            self::assertSame(ErrorCode::InvalidRequest->value, $exception->getCode());
            self::assertTrue($exception->isFrameLevel());
        }
    }

    public function testExample10KeepsTheExtensionField(): void
    {
        $wire = $this->wire(
            'TRPC/1 REQ 12 json 37 web-9z x-prio=high',
            '{"method":"Echo.say","params":["hi"]}',
        );
        $decoder = new FrameDecoder();
        $decoder->feed($wire);
        $frame = $decoder->next();
        self::assertNotNull($frame);
        self::assertSame(['x-prio' => 'high'], $frame->header->meta);
        self::assertSame('web-9z', $frame->header->traceId);
        self::assertSame($wire, (new FrameEncoder())->encode($frame));
    }

    /**
     * @return list<array{0: string, 1: string, 2: FrameKind, 3: int, 4: ?string}>
     */
    public static function examples(): array
    {
        return [
            ['TRPC/1 REQ 1 json 37', '{"method":"Echo.say","params":["hi"]}', FrameKind::Req, 1, null],
            ['TRPC/1 RES 1 json 15 r-7f3a9c01', '{"result":"hi"}', FrameKind::Res, 1, 'r-7f3a9c01'],
            ['TRPC/1 REQ 2 json 40 web-1a2b3c', '{"method":"User.get","params":{"id":42}}', FrameKind::Req, 2, 'web-1a2b3c'],
            ['TRPC/1 RES 2 json 33 web-1a2b3c', '{"result":{"id":42,"name":"Mei"}}', FrameKind::Res, 2, 'web-1a2b3c'],
            ['TRPC/1 REQ 3 json 37', '{"method":"Echo.sya","params":["hi"]}', FrameKind::Req, 3, null],
            [
                'TRPC/1 ERR 3 json 83 r-7f3a9c02',
                '{"error":{"code":-32601,"message":"Method not found","data":{"method":"Echo.sya"}}}',
                FrameKind::Err,
                3,
                'r-7f3a9c02',
            ],
            ['TRPC/1 REQ 4 json 48', '{"method":"Order.pay","params":{"order":"A100"}}', FrameKind::Req, 4, null],
            [
                'TRPC/1 ERR 4 json 56 r-7f3a9c03',
                '{"error":{"code":1002,"message":"Insufficient balance"}}',
                FrameKind::Err,
                4,
                'r-7f3a9c03',
            ],
            ['TRPC/1 REQ 5 json 36', '{"method":"Echo.say","params":["a"]}', FrameKind::Req, 5, null],
            ['TRPC/1 RES 5 json 14 r-7f3a9c04', '{"result":"a"}', FrameKind::Res, 5, 'r-7f3a9c04'],
            ['TRPC/1 REQ 6 json 36', '{"method":"Echo.say","params":["b"]}', FrameKind::Req, 6, null],
            ['TRPC/1 RES 6 json 14 r-7f3a9c05', '{"result":"b"}', FrameKind::Res, 6, 'r-7f3a9c05'],
            ['TRPC/1 PING 9 - 0', '', FrameKind::Ping, 9, null],
            ['TRPC/1 PONG 9 - 0', '', FrameKind::Pong, 9, null],
            [
                'TRPC/1 ERR 10 json 87 r-7f3a9c06',
                '{"error":{"code":-32002,"message":"Frame too large","data":{"max_body_bytes":1048576}}}',
                FrameKind::Err,
                10,
                'r-7f3a9c06',
            ],
            [
                'TRPC/1 ERR 0 json 58 r-7f3a9c07',
                '{"error":{"code":-32600,"message":"Invalid frame header"}}',
                FrameKind::Err,
                0,
                'r-7f3a9c07',
            ],
            [
                'TRPC/1 ERR 11 json 51 r-7f3a9c08',
                '{"error":{"code":-32005,"message":"Shutting down"}}',
                FrameKind::Err,
                11,
                'r-7f3a9c08',
            ],
            ['TRPC/1 RES 12 json 15 web-9z', '{"result":"hi"}', FrameKind::Res, 12, 'web-9z'],
        ];
    }

    private function wire(string $header, string $body): string
    {
        $parts = explode(' ', $header);
        self::assertSame((string) strlen($body), $parts[4], $header);

        return $header . "\n" . $body . "\n";
    }
}
