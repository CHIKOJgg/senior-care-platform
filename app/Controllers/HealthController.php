<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Core\Database;
use Exception;

class HealthController
{
    public function check(): void
    {
        $startTime = microtime(true);
        $dbStatus = 'disconnected';
        $dbLatency = 0.0;
        $healthy = true;
        $errorMsg = null;

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query('SELECT 1');
            if ($stmt && $stmt->fetchColumn() == 1) {
                $dbStatus = 'connected';
                $dbLatency = round((microtime(true) - $startTime) * 1000, 2);
            }
        } catch (Exception $e) {
            $healthy = false;
            $dbStatus = 'error: ' . $e->getMessage();
            $errorMsg = $e->getMessage();
        }

        $storageWritable = is_writable(__DIR__ . '/../../database');

        $status = $healthy ? 'ok' : 'degraded';
        $httpCode = $healthy ? 200 : 503;

        Router::json([
            'status' => $status,
            'app_name' => 'Senior Care Platform («ЗаботаРядом»)',
            'environment' => getenv('APP_ENV') ?: 'local',
            'php_version' => PHP_VERSION,
            'timestamp' => date('c'),
            'checks' => [
                'database' => [
                    'status' => $dbStatus,
                    'latency_ms' => $dbLatency
                ],
                'storage' => [
                    'database_writable' => $storageWritable
                ]
            ],
            'error' => $errorMsg
        ], $httpCode);
    }
}
