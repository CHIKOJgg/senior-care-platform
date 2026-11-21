<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Core\Database;
use App\Core\Logger;
use App\Services\AuthService;
use App\Services\CryptoService;
use Exception;
use Throwable;

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
            $dbStatus = 'error';
            $errorMsg = null;
            Logger::error('Health check DB failure', ['error' => $e->getMessage()]);
        }

        $storagePaths = [
            'database' => __DIR__ . '/../../database',
            'logs' => __DIR__ . '/../../storage/logs',
            'cache' => __DIR__ . '/../../storage/cache',
            'metrics' => __DIR__ . '/../../storage/metrics',
        ];
        $storageChecks = [];
        foreach ($storagePaths as $name => $path) {
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
            $writable = is_dir($path) && is_writable($path);
            $storageChecks[$name . '_writable'] = $writable;
            if (!$writable) {
                $healthy = false;
            }
        }

        $cryptoStatus = 'ok';
        try {
            $probe = 'health-' . bin2hex(random_bytes(4));
            if (CryptoService::decrypt(CryptoService::encrypt($probe)) !== $probe) {
                $cryptoStatus = 'error: roundtrip mismatch';
                $healthy = false;
            }
        } catch (Throwable $e) {
            $cryptoStatus = 'error';
            $healthy = false;
            Logger::error('Health check crypto failure', ['error' => $e->getMessage()]);
        }

        $authStatus = 'ok';
        try {
            $probeToken = AuthService::createToken(['id' => 0, 'role_code' => 'system'], 60);
            if (!AuthService::verifyToken($probeToken)) {
                $authStatus = 'error: token verification failed';
                $healthy = false;
            }
        } catch (Throwable $e) {
            $authStatus = 'error';
            $healthy = false;
            Logger::error('Health check auth failure', ['error' => $e->getMessage()]);
        }

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
                'storage' => $storageChecks,
                'crypto' => [
                    'status' => $cryptoStatus
                ],
                'auth' => [
                    'status' => $authStatus
                ]
            ],
            'error' => $errorMsg
        ], $httpCode);
    }
}
