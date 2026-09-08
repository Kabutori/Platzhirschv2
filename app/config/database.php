<?php
$mysql = [
    'driver' => 'mysql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', 3308),
    'database' => env('DB_DATABASE', 'platzhirsch_platform'),
    'username' => env('DB_USERNAME', 'ph_app'),
    'password' => env('DB_PASSWORD', ''),
    'unix_socket' => '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
    'engine' => 'InnoDB',
];
return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => $mysql,
        'provision' => array_replace($mysql, [
            'username' => env('PROVISION_DB_USERNAME'),
            'password' => env('PROVISION_DB_PASSWORD'),
        ]),
        'tenant' => $mysql,
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', ':memory:'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
