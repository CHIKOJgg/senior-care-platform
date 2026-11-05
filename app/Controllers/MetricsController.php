<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\MetricsService;
use App\Core\Router;

class MetricsController
{
    public function index(): void
    {
        $expected = getenv('METRICS_TOKEN') ?: '';
        if ($expected !== '') {
            $provided = '';
            $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
                $provided = $matches[1];
            } elseif (!empty($_SERVER['HTTP_X_METRICS_TOKEN'])) {
                $provided = (string)$_SERVER['HTTP_X_METRICS_TOKEN'];
            }

            if (!hash_equals($expected, $provided)) {
                Router::json(['error' => 'Forbidden'], 403);
                return;
            }
        }

        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        header('Cache-Control: no-store');
        echo MetricsService::render();
        exit;
    }
}
