<?php
return [
    'enabled' => env('SSO_ENABLED', false),
    'label' => env('SSO_LABEL', 'Unternehmenskonto'),
    'issuer' => env('SSO_ISSUER'),
    'authorization_url' => env('SSO_AUTHORIZATION_URL'),
    'token_url' => env('SSO_TOKEN_URL'),
    'jwks_url' => env('SSO_JWKS_URL'),
    'client_id' => env('SSO_CLIENT_ID'),
    'client_secret' => env('SSO_CLIENT_SECRET'),
];
