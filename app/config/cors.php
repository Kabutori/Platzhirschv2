<?php
// Admin APIs are same-origin. WidgetController owns token-specific origin
// validation and preflight replies; a global wildcard would override its policy.
return [
    'paths' => [],
    'allowed_methods' => [],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
