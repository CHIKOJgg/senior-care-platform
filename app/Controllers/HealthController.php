<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Core\Database;
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
            $dbStatus = 'error: ' . $e->getMessage();
            $errorMsg = $e->getMessage();
        }

        $storageWritable = is_writable(__DIR__ . '/../../database');

        $cryptoStatus = 'ok';
        try {
            $probe = 'health-' . bin2hex(random_bytes(4));
            if (CryptoService::decrypt(CryptoService::encrypt($probe)) !== $probe) {
                $cryptoStatus = 'error: roundtrip mismatch';
                $healthy = false;
            }
        } catch (Throwable $e) {
            $cryptoStatus = 'error: ' . $e->getMessage();
            $healthy = false;
        }

        $authStatus = 'ok';
        try {
            $probeToken = AuthService::createToken(['id' => 0, 'role_code' => 'system'], 60);
            if (!AuthService::verifyToken($probeToken)) {
                $authStatus = 'error: token verification failed';
                $healthy = false;
            }
        } catch (Throwable $e) {
            $authStatus = 'error: ' . $e->getMessage();
            $healthy = false;
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
                'storage' => [
                    'database_writable' => $storageWritable
                ],
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
