<?php

declare(strict_types=1);

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
use App\Controllers\RegionController;
use App\Controllers\ChatController;
use App\Controllers\CoordinatorController;
use App\Controllers\VolunteerController;
use App\Controllers\HealthController;
use App\Controllers\MetricsController;
use App\Controllers\PrivacyController;
use App\Controllers\SosController;

$router = new Router();


$router->get('/api/health', [HealthController::class, 'check']);
$router->get('/api/metrics', [MetricsController::class, 'index']);


$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->get('/api/auth/me', [AuthController::class, 'me']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->post('/api/auth/logout-all', [AuthController::class, 'logoutAll']);
$router->post('/api/auth/password', [AuthController::class, 'changePassword']);
$router->post('/api/auth/reset-password', [AuthController::class, 'resetPassword']);
$router->post('/api/admin/users', [AuthController::class, 'adminCreateUser']);
$router->post('/api/coordinator/reset-code', [AuthController::class, 'requestPasswordReset']);


$router->get('/api/requests', [HelpRequestController::class, 'index']);
$router->get('/api/requests/my', [HelpRequestController::class, 'myRequests']);
$router->get('/api/requests/volunteer', [HelpRequestController::class, 'volunteerTasks']);
$router->get('/api/requests/overdue', [CoordinatorController::class, 'watchdog']);
$router->get('/api/requests/{id}', [HelpRequestController::class, 'show']);
$router->post('/api/requests', [HelpRequestController::class, 'store']);
$router->post('/api/requests/{id}/accept', [HelpRequestController::class, 'accept']);
$router->post('/api/requests/{id}/complete', [HelpRequestController::class, 'complete']);
$router->post('/api/requests/{id}/rate', [HelpRequestController::class, 'rate']);
$router->post('/api/requests/{id}/cancel', [HelpRequestController::class, 'cancel']);


$router->post('/api/antifraud/check', [AntiFraudController::class, 'check']);
$router->get('/api/antifraud/alerts', [AntiFraudController::class, 'alerts']);


$router->get('/api/education', [EducationController::class, 'index']);
$router->get('/api/education/{id}', [EducationController::class, 'show']);
$router->post('/api/education', [EducationController::class, 'create']);
$router->put('/api/education/{id}', [EducationController::class, 'update']);
$router->delete('/api/education/{id}', [EducationController::class, 'delete']);


$router->get('/api/chat/{id}', [ChatController::class, 'messages']);
$router->post('/api/chat/{id}', [ChatController::class, 'send']);


$router->get('/api/coordinator/stats', [CoordinatorController::class, 'stats']);
$router->get('/api/coordinator/volunteers', [CoordinatorController::class, 'allVolunteers']);
$router->get('/api/coordinator/volunteers/pending', [CoordinatorController::class, 'pendingVolunteers']);
$router->get('/api/regions', [RegionController::class, 'index']);
$router->get('/api/categories', [CoordinatorController::class, 'categories']);
$router->get('/api/coordinator/categories', [CoordinatorController::class, 'categories']);
$router->post('/api/coordinator/categories', [CoordinatorController::class, 'createCategory']);
$router->put('/api/coordinator/categories/{id}', [CoordinatorController::class, 'updateCategory']);
$router->delete('/api/coordinator/categories/{id}', [CoordinatorController::class, 'deleteCategory']);
$router->post('/api/coordinator/volunteers/{id}/rehabilitate', [CoordinatorController::class, 'rehabilitateVolunteer']);
$router->post('/api/coordinator/volunteers/{id}/verify', [CoordinatorController::class, 'verifyVolunteer']);
$router->post('/api/coordinator/volunteers/{id}/reject', [CoordinatorController::class, 'rejectVolunteer']);
$router->post('/api/coordinator/volunteers/{id}/restore', [CoordinatorController::class, 'restoreVolunteer']);
$router->get('/api/coordinator/pending-volunteers', [CoordinatorController::class, 'pendingVolunteers']);
$router->post('/api/coordinator/verify-volunteer/{id}', [CoordinatorController::class, 'verifyVolunteer']);
$router->post('/api/coordinator/reject-volunteer/{id}', [CoordinatorController::class, 'rejectVolunteer']);
$router->get('/api/coordinator/requests', [CoordinatorController::class, 'allRequests']);
$router->post('/api/coordinator/requests/{id}/status', [CoordinatorController::class, 'updateRequestStatus']);
$router->post('/api/coordinator/alerts', [CoordinatorController::class, 'createAlert']);
$router->delete('/api/coordinator/alerts/{id}', [CoordinatorController::class, 'deleteAlert']);
$router->get('/api/volunteers/leaderboard', [CoordinatorController::class, 'leaderboard']);


$router->get('/api/volunteers/my-badge', [VolunteerController::class, 'myBadge']);
$router->get('/api/volunteers/badge/{token}', [VolunteerController::class, 'verifyPublicBadge']);
$router->get('/api/volunteers/badge/{token}/svg', [VolunteerController::class, 'renderBadgeSvg']);
$router->post('/api/volunteers/verify-identity', [VolunteerController::class, 'verifyIdentity']);


$router->post('/api/consent', [PrivacyController::class, 'recordConsent']);
$router->get('/api/consent', [PrivacyController::class, 'getConsents']);
$router->post('/api/consent/revoke', [PrivacyController::class, 'revokeConsent']);
$router->delete('/api/consent', [PrivacyController::class, 'revokeConsent']);
$router->post('/api/privacy/anonymize', [PrivacyController::class, 'anonymize']);
$router->post('/api/elderly/{id}/anonymize', [PrivacyController::class, 'anonymizeElderly']);


$router->post('/api/sos', [SosController::class, 'trigger']);
$router->get('/api/sos/active', [SosController::class, 'activeAlerts']);
$router->get('/api/sos/{id}', [SosController::class, 'show']);
$router->post('/api/sos/{id}/resolve', [SosController::class, 'resolveAlert']);


$router->get('/api/coordinator/sos-alerts', [SosController::class, 'activeAlerts']);
$router->post('/api/coordinator/sos-alerts/{id}/resolve', [SosController::class, 'resolveAlert']);
$router->get('/api/coordinator/alerts', [CoordinatorController::class, 'alerts']);


$router->get('/api/coordinator/watchdog', [CoordinatorController::class, 'watchdog']);
$router->get('/api/coordinator/tasks/overdue', [CoordinatorController::class, 'watchdog']);
$router->get('/api/requests/overdue', [CoordinatorController::class, 'watchdog']);
$router->post('/api/tasks/watchdog/run', [CoordinatorController::class, 'runWatchdog']);
$router->post('/api/coordinator/requests/{id}/reassign', [CoordinatorController::class, 'reassignRequest']);


$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';

if ($method === 'OPTIONS') {
    $corsOrigin = getenv('APP_CORS_ORIGIN') ?: (getenv('APP_ENV') === 'production' ? '' : '*');
    if ($corsOrigin !== '') {
        header('Access-Control-Allow-Origin: ' . $corsOrigin);
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    $allowedHeaders = 'Content-Type, Authorization, X-Auth-Token';
    if (getenv('APP_ENV') !== 'production') {
        $allowedHeaders .= ', X-Demo-User';
    }
    header('Access-Control-Allow-Headers: ' . $allowedHeaders);
    exit(0);
}


try {
    $router->dispatch($method, $uri);
} catch (\Throwable $e) {
    \App\Core\Logger::error('Unhandled API exception', [
        'method' => $method,
        'uri' => $uri,
        'exception' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode(['error' => 'Внутренняя ошибка сервера', 'code' => 'INTERNAL_ERROR'], JSON_UNESCAPED_UNICODE);
    exit;
}

