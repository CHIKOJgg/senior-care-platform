<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Models\FraudAlert;
use App\Services\AntiFraudService;

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
        // Public advisory feed: active anti-fraud warnings shown on the senior's home screen.
        $alerts = FraudAlert::getActiveAlerts();
        Router::json([
            'success' => true,
            'data' => $alerts
        ]);
    }
}
