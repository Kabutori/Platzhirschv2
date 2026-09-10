<?php
return [
    'name' => 'Platzhirsch',
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost:8378'),
    'timezone' => env('APP_TIMEZONE', 'Europe/Berlin'),
    'locale' => 'de',
    'fallback_locale' => 'en',
    'faker_locale' => 'de_DE',
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',
    'previous_keys' => [],
];
