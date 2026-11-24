<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Services\AuthService;
use App\Services\Emergency\MchsEmergencyAdapter;
use App\Models\SosAlert;
use App\Models\User;

class SosController
{
    /**
     * Trigger Priority 0 Emergency SOS Alert
     * Route: POST /api/sos
     */
    public function trigger(): void
    {
        // Require a real session (HttpOnly cookie from the web UI, or an explicit bearer token).
        // This still blocks the local demo fallback for truly anonymous requests.
        $hasExplicitAuth = !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
            || !empty($_SERVER['HTTP_X_AUTH_TOKEN'])
            || !empty($_COOKIE['auth_token']);
        if (!$hasExplicitAuth) {
            Router::json(['error' => 'Требуется авторизация для вызова экстренной помощи'], 401);
        }

        $currentUser = AuthService::getCurrentUser();
        if (!$currentUser) {
            Router::json(['error' => 'Недействительный токен авторизации'], 401);
        }

        // Emergency traffic must never be rejected because another household member
        // (or another kiosk behind the same NAT address) used their share of an IP
        // bucket. Limit the authenticated account instead, with enough headroom for
        // repeated panic presses.
        $sosRateKey = 'sos_trigger:user:' . (int)$currentUser['user_id'];
        if (!RateLimiter::check($sosRateKey, 60, 60)) {
            Logger::security('SOS rate limit hit', ['user_id' => (int)$currentUser['user_id']]);
            Router::json(['error' => 'Слишком много экстренных запросов. Пожалуйста, подождите минуту.'], 429);
        }

        $input = Router::getJsonInput();

        // Resolve elderly citizen identity
        $role = $currentUser['role_code'] ?? '';
        if ($role === 'elderly') {
            $elderlyId = (int)$currentUser['user_id'];
        } elseif (in_array($role, ['admin', 'coordinator'], true)) {
            if (empty($input['elderly_id'])) {
                Router::json(['error' => 'Укажите идентификатор подопечного'], 400);
            }
            $elderlyId = (int)$input['elderly_id'];
        } else {
            Router::json(['error' => 'Вызов SOS доступен только подопечным, администраторам и координаторам'], 403);
        }

        // Resolve elderly citizen identity
        $target = User::getWithProfile($elderlyId);
        if (!$target || ($target['role_code'] ?? '') !== 'elderly') {
            Router::json(['error' => 'Подопечный с указанным идентификатором не найден'], 404);
        }
        $profile = $target['profile'] ?? null;

        $lat = isset($input['latitude']) && is_numeric($input['latitude'])
            ? (float)$input['latitude']
            : (float)($profile['latitude'] ?? 53.9168);

        $lon = isset($input['longitude']) && is_numeric($input['longitude'])
            ? (float)$input['longitude']
            : (float)($profile['longitude'] ?? 27.5862);

        // Coordinate validation
        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            Router::json(['error' => 'Некорректные географические координаты вызова SOS'], 400);
        }

        $address = !empty($input['address'])
            ? (string)$input['address']
            : (string)($profile['address'] ?? 'Республика Беларусь');

        $reason = !empty($input['reason'])
            ? (string)$input['reason']
            : 'Экстренная тревога подопечного: требуется срочная помощь';

        $address = mb_substr(trim($address), 0, 500, 'UTF-8');
        $reason = mb_substr(trim($reason), 0, 1000, 'UTF-8');

        // Persist the Priority 0 alert first: a notification that cannot be
        // stored must never leave an orphaned external dispatch.
        $alertId = SosAlert::create([
            'elderly_id' => $elderlyId,
            'latitude' => $lat,
            'longitude' => $lon,
            'address' => $address,
            'reason' => $reason,
            'status' => 'ACTIVE',
            'priority' => 0,
            'emercom_ticket_id' => null,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Modular EMERCOM / 112 dispatch
        $emercomAdapter = new MchsEmergencyAdapter();
        $dispatchData = [
            'elderly_id' => $elderlyId,
            'latitude' => $lat,
            'longitude' => $lon,
            'address' => $address,
            'reason' => $reason,
            'simulate_network_failure' => !empty($input['simulate_network_failure'])
        ];
        $dispatchResult = $emercomAdapter->dispatchEmergency($dispatchData);
        $ticketId = $dispatchResult['ticket_id'] ?? $dispatchResult['incident_code'] ?? null;
        if ($ticketId !== null) {
            SosAlert::update($alertId, ['emercom_ticket_id' => $ticketId]);
        }

        $dispatchMode = (string)($dispatchResult['mode'] ?? 'simulated');
        $emercomNotified = $dispatchMode === 'live' && ($dispatchResult['success'] ?? false) === true;
        $message = $emercomNotified
            ? 'Сигнал SOS принят! Координаторы и экстренная служба МЧС (112) оповещены.'
            : 'Сигнал SOS принят координатором ТЦСОН. Интеграция с МЧС (112) работает в тестовом режиме.';

        Logger::security("Priority 0 SOS created #{$alertId} for Elderly #{$elderlyId}. Dispatch mode: {$dispatchMode}, ticket: {$ticketId}");

        Router::json([
            'success' => true,
            'message' => $message,
            'sos_id' => (int)$alertId,
            'status' => 'ACTIVE',
            'priority' => 0,
            'emercom_ticket_id' => $ticketId,
            'emercom_notified' => $emercomNotified,
            'dispatch_mode' => $dispatchMode,
            'dispatch' => $dispatchResult,
            'created_at' => date('Y-m-d H:i:s')
        ], 201);
    }

    /**
     * Return the authenticated elderly citizen's own active SOS alerts.
     * Route: GET /api/sos/my-active
     */
    public function myActive(): void
    {
        $user = AuthService::requireAuth(['elderly']);
        $alerts = SosAlert::where('elderly_id', (int)$user['user_id']);
        $alerts = array_values(array_filter(
            $alerts,
            static fn(array $alert): bool => in_array($alert['status'] ?? '', ['ACTIVE', 'ACKNOWLEDGED'], true)
        ));

        Router::json([
            'success' => true,
            'count' => count($alerts),
            'data' => $alerts,
            'alerts' => $alerts
        ]);
    }

    /**
     * List active Priority 0 SOS alerts for Coordinator / Admin
     * Routes: GET /api/sos/active, GET /api/coordinator/sos-alerts, GET /api/coordinator/alerts
     */
    public function activeAlerts(): void
    {
        AuthService::requireAuth(['admin', 'coordinator']);

        $alerts = SosAlert::getActiveAlerts();

        Router::json([
            'success' => true,
            'count' => count($alerts),
            'data' => $alerts,
            'alerts' => $alerts
        ]);
    }

    /**
     * Show single SOS alert details
     * Route: GET /api/sos/{id}
     */
    public function show(string $id): void
    {
        AuthService::requireAuth(['admin', 'coordinator']);
        $alert = SosAlert::find((int)$id);

        if (!$alert) {
            Router::json(['error' => 'Экстренное оповещение не найдено'], 404);
        }

        Router::json([
            'success' => true,
            'data' => $alert
        ]);
    }

    /**
     * Resolve active SOS alert
     * Routes: POST /api/sos/{id}/resolve, POST /api/coordinator/sos-alerts/{id}/resolve
     */
    public function resolveAlert(string $id): void
    {
        $admin = AuthService::requireAuth(['admin', 'coordinator']);
        $alertId = (int)$id;

        $alert = SosAlert::find($alertId);
        if (!$alert) {
            Router::json(['error' => 'Экстренное оповещение не найдено'], 404);
        }

        SosAlert::resolveAlert($alertId);

        Logger::info("SOS Alert #{$alertId} resolved by Admin/Coordinator #{$admin['user_id']}");

        Router::json([
            'success' => true,
            'message' => "Экстренная тревога #{$alertId} успешно разрешена",
            'sos_id' => $alertId,
            'status' => 'RESOLVED',
            'resolved_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Resolve all active SOS signals for one senior in one action.
     *
     * Routes:
     *   POST /api/sos/elderly/{id}/resolve-all
     *   POST /api/coordinator/sos-alerts/elderly/{id}/resolve-all
     */
    public function resolveAllForElderly(string $id): void
    {
        $admin = AuthService::requireAuth(['admin', 'coordinator']);
        $elderlyId = (int)$id;

        $target = User::getWithProfile($elderlyId);
        if (!$target || ($target['role_code'] ?? '') !== 'elderly') {
            Router::json(['error' => 'Подопечный не найден'], 404);
        }

        $resolvedCount = SosAlert::resolveAllForElderly($elderlyId);
        if ($resolvedCount > 0) {
            Logger::security("Resolved {$resolvedCount} active SOS signal(s) for Elderly #{$elderlyId} by Admin/Coordinator #{$admin['user_id']}");
        }

        Router::json([
            'success' => true,
            'elderly_id' => $elderlyId,
            'resolved_count' => $resolvedCount,
            'status' => $resolvedCount > 0 ? 'RESOLVED' : 'NO_ACTIVE_ALERTS',
            'message' => $resolvedCount > 0
                ? "Разрешено сигналов SOS: {$resolvedCount}"
                : 'Активных сигналов SOS для этого подопечного нет',
            'resolved_at' => date('Y-m-d H:i:s')
        ]);
    }
}
