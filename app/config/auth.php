<?php
return [
    'defaults' => ['guard' => 'web', 'passwords' => 'users'],
    'guards' => [
        'web' => ['driver' => 'session', 'provider' => 'users'],
        'administration' => ['driver' => 'session', 'provider' => 'users'],
        'restaurant' => ['driver' => 'session', 'provider' => 'users'],
    ],
    'providers' => ['users' => ['driver' => 'eloquent', 'model' => App\Models\User::class]],
    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 30,
            'throttle' => 60,
        ],
    ],
    'password_timeout' => 10800,
];
