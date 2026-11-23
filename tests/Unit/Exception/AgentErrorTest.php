<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Exception;

use PhpServer\Exception\AgentError;
use PhpServer\Exception\BusinessException;
use PhpServer\Exception\PhpServerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentErrorTest extends TestCase
{
    public function testDataOmitsEmptyOptionalFields(): void
    {
        $error = new AgentError(1001, 'Order not found', 'ORDER_NOT_FOUND', 'shop.example', true);
        $exception = new BusinessException($error);

        self::assertSame(1001, $exception->getCode());
        self::assertSame('Order not found', $exception->getMessage());
        self::assertInstanceOf(PhpServerException::class, $exception);
        self::assertSame($error, $exception->agentError());
        self::assertSame([
            'reason' => 'ORDER_NOT_FOUND',
            'domain' => 'shop.example',
            'retryable' => true,
        ], $error->data());
    }

    #[DataProvider('rejected')]
    public function testRejectsAShapeAnAgentCannotTrust(int $code, string $message, string $reason, string $domain): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AgentError($code, $message, $reason, $domain);
    }

    /**
     * @return list<array{int, string, string, string}>
     */
    public static function rejected(): array
    {
        return [
            [0, 'Order not found', 'ORDER_NOT_FOUND', 'shop.example'],
            [-32603, 'Internal error', 'ORDER_NOT_FOUND', 'shop.example'],
            [1001, "Order\nnot found", 'ORDER_NOT_FOUND', 'shop.example'],
            [1001, 'Order not found', 'orderNotFound', 'shop.example'],
            [1001, 'Order not found', 'ORDER_NOT_FOUND', 'Shop.Example'],
        ];
    }
}
