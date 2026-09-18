<?php
// Router for PHP built-in web server: php -S 0.0.0.0:8000 router.php

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/public' . $uri;

// Serve static files if they exist
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

// Route API endpoints to api.php
if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/public/api.php';
    exit;
}

// Everything else to index.php
require __DIR__ . '/public/index.php';
