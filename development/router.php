<?php
// PHP's local development server serves only the application's public directory.
$public = realpath(__DIR__ . '/../app/public');
$path = realpath($public . rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
if ($path && str_starts_with($path, $public . DIRECTORY_SEPARATOR) && is_file($path)) {
    return false;
}
require $public . '/index.php';
