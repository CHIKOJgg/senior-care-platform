<?php
// Router for PHP built-in web server

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/public' . $uri;

// 1. Static files from /public (excluding PHP scripts from raw readfile)
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        require $file;
        exit;
    }
    $mimes = [
        'css' => 'text/css; charset=UTF-8',
        'js'  => 'application/javascript; charset=UTF-8',
        'json'=> 'application/json; charset=UTF-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg'=> 'image/jpeg',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff'=> 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf' => 'font/ttf'
    ];
    $mime = $mimes[$ext] ?? 'application/octet-stream';
    header("Content-Type: {$mime}");
    header("Content-Length: " . filesize($file));
    readfile($file);
    exit;
}

// 2. API Endpoints
if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/public/api.php';
    exit;
}

// 3. Public Verification route (e.g. /verify or /verify/TOKEN or /verify.php)
if (str_starts_with($uri, '/verify') || $uri === '/verify.php') {
    require __DIR__ . '/public/verify.php';
    exit;
}

// 4. Main HTML application
require __DIR__ . '/public/index.php';
