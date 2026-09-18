<?php

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/../app/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\HelpRequestController;
use App\Controllers\AntiFraudController;
use App\Controllers\EducationController;
use App\Controllers\ChatController;
use App\Controllers\CoordinatorController;

$router = new Router();

// Auth routes
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/register', [AuthController::class, 'register']);

// Help Requests routes
$router->get('/api/requests', [HelpRequestController::class, 'index']);
$router->get('/api/requests/{id}', [HelpRequestController::class, 'show']);
$router->post('/api/requests', [HelpRequestController::class, 'store']);
$router->post('/api/requests/{id}/accept', [HelpRequestController::class, 'accept']);
$router->post('/api/requests/{id}/complete', [HelpRequestController::class, 'complete']);

// Anti-fraud routes
$router->post('/api/antifraud/check', [AntiFraudController::class, 'check']);
$router->get('/api/antifraud/alerts', [AntiFraudController::class, 'alerts']);

// Educational materials
$router->get('/api/education', [EducationController::class, 'index']);

// Chat routes
$router->get('/api/chat/{id}', [ChatController::class, 'messages']);
$router->post('/api/chat/{id}', [ChatController::class, 'send']);

// Coordinator & Gamification routes
$router->get('/api/coordinator/stats', [CoordinatorController::class, 'stats']);
$router->get('/api/coordinator/pending-volunteers', [CoordinatorController::class, 'pendingVolunteers']);
$router->post('/api/coordinator/verify-volunteer/{id}', [CoordinatorController::class, 'verifyVolunteer']);
$router->post('/api/coordinator/alerts', [CoordinatorController::class, 'createAlert']);
$router->get('/api/volunteers/leaderboard', [CoordinatorController::class, 'leaderboard']);

// Dispatch
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';

if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit(0);
}

$router->dispatch($method, $uri);
