<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Ipc;

use PhpServer\Exception\ProcessException;
use PhpServer\Ipc\Codec;
use PhpServer\Ipc\Command;
use PhpServer\Ipc\Message;
use PHPUnit\Framework\TestCase;

final class CodecTest extends TestCase
{
    public function testRoundTripIsNotPhpSerialize(): void
    {
        $body = "requests=2\nerrors=0\n";
        $codec = new Codec();
        $bytes = $codec->encode(new Message(Command::MetricsResult, $body));
        self::assertStringStartsWith(Codec::MAGIC, $bytes);
        self::assertStringNotContainsString('O:', $bytes);
        self::assertStringNotContainsString('a:', $bytes);
        self::assertNotSame(serialize(['requests' => 2]), $bytes);

        $decoded = new Codec();
        $decoded->feed($bytes);
        $message = $decoded->next();
        self::assertInstanceOf(Message::class, $message);
        self::assertSame(Command::MetricsResult, $message->command);
        self::assertSame($body, $message->body);
        self::assertNull($decoded->next());
    }

    public function testByteAtATimeAndTwoFrames(): void
    {
        $codec = new Codec();
        $bytes = $codec->encode(new Message(Command::Ping, ''))
            . $codec->encode(new Message(Command::Drain, 'stop-accept'));
        $reader = new Codec();
        $messages = [];
        foreach (str_split($bytes, 1) as $byte) {
            $reader->feed($byte);
            while (($message = $reader->next()) instanceof Message) {
                $messages[] = $message;
            }
        }
        self::assertSame(Command::Ping, $messages[0]->command);
        self::assertSame('', $messages[0]->body);
        self::assertSame(Command::Drain, $messages[1]->command);
        self::assertSame('stop-accept', $messages[1]->body);
    }

    public function testBadMagicFails(): void
    {
        $reader = new Codec();
        $reader->feed('XXXX' . "\x01\x00\x00\x00");
        $this->expectException(ProcessException::class);
        $reader->next();
    }
}
