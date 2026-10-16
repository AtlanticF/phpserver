<?php

declare(strict_types=1);

/**
 * Minimal Echo worker. Defaults fill every key that is not set here.
 * The class is not shipped until M4; M1 only checks that the name is present.
 */
return [
    'admin' => [
        'listen' => '127.0.0.1:9100',
    ],
    'workers' => [
        'Echo' => [
            'listen' => '127.0.0.1:9000',
            'worker_class' => 'PhpServer\\Examples\\Echo\\EchoWorker',
            'child_count' => 1,
        ],
    ],
];
