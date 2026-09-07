<?php
// Run the actual production entry point in an isolated PHP process.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/up';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['HTTP_HOST'] = 'localhost';
require dirname(__DIR__, 2) . '/public/index.php';
fwrite(STDOUT, "\nENTRY_STATUS=" . http_response_code() . "\n");
