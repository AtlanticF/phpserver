<?php

declare(strict_types=1);

/**
 * Full example. Every key below is optional except each worker's listen and worker_class.
 * See docs/configuration.md for the environment-variable names.
 */
return [
    'pid_file' => '',
    'user' => '',
    'group' => '',
    'log' => [
        'level' => 'info',
        'json' => false,
        'timezone' => 'UTC',
        'body_truncate_bytes' => 256,
    ],
    'admin' => [
        'enabled' => true,
        'listen' => '127.0.0.1:9100',
        'token' => '',
        'metrics' => true,
    ],
    'workers' => [
        'Rpc' => [
            'listen' => '127.0.0.1:9000',
            'worker_class' => 'PhpServer\\Examples\\Rpc\\RpcWorker',
            'bootstrap' => '',
            'child_count' => 4,
            'backlog' => 511,
            'max_connections' => 1024,
            'recv_timeout' => 30,
            'send_timeout' => 30,
            'idle_timeout' => 60,
            'process_timeout' => 30,
            // text-rpc/1 body cap. Hard maximum is 64 MiB. The frame buffer adds 260 bytes
            // for the header line and the two CR LF markers.
            'max_body_bytes' => 1048576,
            'max_frame_bytes' => 1048836,
            'pipeline_backlog' => 32,
            // 0 disables the in-process recycle valve.
            'max_requests' => 0,
            'max_work_time' => 0,
            'recycle_stagger' => 0,
        ],
    ],
];
