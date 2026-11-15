<?php

declare(strict_types=1);

namespace PhpServer\Examples\Echo;

use PhpServer\Worker\AbstractWorker;
use PhpServer\Worker\Input;
use PhpServer\Worker\Output;

/**
 * Minimal HTTP plaintext worker. Any complete request gets a body of `hello`.
 *
 * The response is HTTP/1.1 so `curl` prints `hello`. The connection closes
 * after that one response.
 */
final class EchoWorker extends AbstractWorker
{
    private const string BODY = 'hello';

    private const int HEADER_LIMIT = 8192;

    public function dealInput(string $buffer): Input
    {
        $end = strpos($buffer, "\r\n\r\n");
        if ($end === false) {
            if (strlen($buffer) > self::HEADER_LIMIT) {
                return Input::failed(strlen($buffer), $this->response(400, 'Bad Request', 'bad request'), true);
            }

            return Input::needMore();
        }

        return Input::ready($end + 4);
    }

    public function dealProcess(string $message): Output
    {
        return new Output($this->response(200, 'OK', self::BODY), true);
    }

    private function response(int $status, string $reason, string $body): string
    {
        return 'HTTP/1.1 ' . $status . ' ' . $reason . "\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $body;
    }
}
