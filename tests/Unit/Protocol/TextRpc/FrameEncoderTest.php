<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Protocol\TextRpc;

use PhpServer\Protocol\TextRpc\Frame;
use PhpServer\Protocol\TextRpc\FrameDecoder;
use PhpServer\Protocol\TextRpc\FrameEncoder;
use PhpServer\Protocol\TextRpc\FrameKind;
use PhpServer\Protocol\TextRpc\Header;
use PHPUnit\Framework\TestCase;

final class FrameEncoderTest extends TestCase
{
    public function testEmptyBodyAndBinaryBodyRoundTripWithLf(): void
    {
        $encoder = new FrameEncoder();
        $frames = [
            new Frame(new Header(FrameKind::Ping, 0, '-', 0, null, []), ''),
            new Frame(new Header(FrameKind::Req, 1, 'json', 4, 'web-1', ['x-prio' => 'high']), "\x00\xFF\n\r"),
        ];
        foreach ($frames as $frame) {
            $wire = $encoder->encode($frame);
            $newline = strpos($wire, "\n");
            self::assertNotFalse($newline);
            self::assertStringNotContainsString("\r", substr($wire, 0, $newline));
            self::assertStringEndsWith($frame->body . "\n", $wire);

            $decoder = new FrameDecoder();
            $decoder->feed($wire);
            self::assertEquals($frame, $decoder->next());
            self::assertSame($wire, $encoder->encode($frame));
        }
    }

    public function testHeaderLongerThan256BytesIsRejected(): void
    {
        $trace = str_repeat('a', 64);
        $value = str_repeat('b', 128);
        $header = new Header(FrameKind::Req, 1, 'json', 0, $trace, [
            'x-one' => $value,
            'x-two' => $value,
        ]);
        $frame = new Frame($header, '');

        $this->expectException(\InvalidArgumentException::class);
        (new FrameEncoder())->encode($frame);
    }

    public function testBodyLengthMustMatchTheHeader(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Frame(new Header(FrameKind::Ping, 1, '-', 0, null, []), 'x');
    }
}
