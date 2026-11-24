<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Models\FraudAlert;
use App\Models\User;
use App\Services\AntiFraudService;
use App\Services\AuthService;
use App\Services\RegionService;

class AntiFraudController
{
    public function check(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!\App\Core\RateLimiter::check("antifraud_check:{$ip}", 30, 60)) {
            \App\Core\Router::json(['error' => 'Слишком много запросов. Подождите минуту.'], 429);
        }

        $input = Router::getJsonInput();
        $text = is_string($input['text'] ?? null) ? trim($input['text']) : '';

        if (!$text) {
            Router::json(['error' => 'Введите текст сообщения или фразы для проверки'], 400);
        }
        if (mb_strlen($text) > 5000) {
            Router::json(['error' => 'Текст для проверки слишком длинный (максимум 5000 символов)'], 400);
        }

        $result = AntiFraudService::analyze($text);

        Router::json([
            'success' => true,
            'result' => $result
        ]);
    }

    public function alerts(): void
    {
        // Public advisory feed: global warnings plus warnings for the viewer's
        // region. Coordinators only receive their own region + global alerts.
        $user = AuthService::getCurrentUser();
        $region = null;
        if ($user) {
            if (($user['role_code'] ?? '') === 'coordinator') {
                $region = AuthService::regionScope($user);
            } elseif (in_array($user['role_code'] ?? '', ['elderly', 'volunteer'], true)) {
                $profile = User::getWithProfile((int)$user['user_id']);
                $region = RegionService::normalize($profile['profile']['region_code'] ?? $user['region_code'] ?? null);
            }
        }

        $alerts = FraudAlert::getActiveAlerts($region);
        Router::json([
            'success' => true,
            'region' => $region,
            'data' => $alerts
        ]);
    }
}
