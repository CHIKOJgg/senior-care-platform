<?php

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
        $text = trim($input['text'] ?? '');

        if (!$text) {
            Router::json(['error' => 'Введите текст сообщения или фразы для проверки'], 400);
        }

        $result = AntiFraudService::analyze($text);

        Router::json([
            'success' => true,
            'result' => $result
        ]);
    }

    public function alerts(): void
    {

        $alerts = FraudAlert::getActiveAlerts();
        Router::json([
            'success' => true,
            'data' => $alerts
        ]);
    }
}
