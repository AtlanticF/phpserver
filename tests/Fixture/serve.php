<?php

declare(strict_types=1);

use PhpServer\Tests\Fixture\TextRpcFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$args = $_SERVER['argv'] ?? null;
$mode = is_array($args) ? ($args[1] ?? null) : null;
if (!is_string($mode) || $mode === '') {
    fwrite(STDERR, "mode required\n");
    exit(1);
}

exit((new TextRpcFixture())->serve($mode));
