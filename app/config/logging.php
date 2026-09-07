<?php
return [
    'default' => 'daily',
    'channels' => [
        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => 'info',
            'days' => 14,
            'replace_placeholders' => true,
        ],
        'stderr' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
        ],
    ],
];
