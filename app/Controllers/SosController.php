<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Services\AuthService;
use App\Services\Emergency\MchsEmergencyAdapter;
use App\Models\SosAlert;
use App\Models\ElderlyProfile;
use App\Models\User;

class SosController
{




    public function trigger(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!RateLimiter::check("sos_trigger:{$ip}", 30, 60)) {
            Router::json(['error' => 'Слишком много экстренных запросов. Пожалуйста, подождите минуту.'], 429);
        }



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

        $input = Router::getJsonInput();


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


        $profile = ElderlyProfile::find($elderlyId);
        $user = User::find($elderlyId);

        $lat = isset($input['latitude']) && is_numeric($input['latitude'])
            ? (float)$input['latitude']
            : (float)($profile['latitude'] ?? 53.9168);

        $lon = isset($input['longitude']) && is_numeric($input['longitude'])
            ? (float)$input['longitude']
            : (float)($profile['longitude'] ?? 27.5862);


        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            Router::json(['error' => 'Некорректные географические координаты вызова SOS'], 400);
        }

        $address = !empty($input['address'])
            ? (string)$input['address']
            : (string)($profile['address'] ?? 'Республика Беларусь');

        $reason = !empty($input['reason'])
            ? (string)$input['reason']
            : 'Экстренная тревога подопечного: требуется срочная помощь';


        $gpsProvided = isset($input['latitude'], $input['longitude'])
            && is_numeric($input['latitude'])
            && is_numeric($input['longitude']);
        if ($gpsProvided) {
            try {
                ElderlyProfile::update($elderlyId, [
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'address' => $address,
                ]);
            } catch (\Throwable $e) {
                Logger::warning("Failed to refresh elderly GPS profile from SOS for user #{$elderlyId}: " . $e->getMessage());
            }
        }


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


        $alertId = SosAlert::create([
            'elderly_id' => $elderlyId,
            'latitude' => $lat,
            'longitude' => $lon,
            'address' => $address,
            'reason' => $reason,
            'status' => 'ACTIVE',
            'priority' => 0,
            'emercom_ticket_id' => $ticketId,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        Logger::security("Priority 0 SOS created #{$alertId} for Elderly #{$elderlyId}. EMERCOM ticket: {$ticketId}");

        Router::json([
            'success' => true,
            'message' => 'Сигнал SOS принят! Координаторы и экстренная служба МЧС (112) оповещены.',
            'sos_id' => (int)$alertId,
            'status' => 'ACTIVE',
            'priority' => 0,
            'emercom_ticket_id' => $ticketId,
            'emercom_notified' => true,
            'dispatch' => $dispatchResult,
            'created_at' => date('Y-m-d H:i:s')
        ], 201);
    }





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
}
