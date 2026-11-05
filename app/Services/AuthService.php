<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Router;
use App\Core\Logger;
use App\Models\User;

class AuthService
{
    public const TTL_SESSION = 86400 * 7;
    public const TTL_DEVICE = 86400 * 365;

    private static function getSecretKey(): string
    {
        $secret = getenv('APP_SECRET') ?: '';
        if ($secret !== '') {
            return $secret;
        }
        if (self::isProduction()) {
            throw new \RuntimeException('APP_SECRET must be set in production');
        }
        return 'local_dev_only_secret_' . md5(__DIR__);
    }

    public static function isProduction(): bool
    {
        return (getenv('APP_ENV') ?: 'local') === 'production';
    }

    /**
     * Create an HMAC-SHA256 signed stateless token
     */
    public static function createToken(array $user, int $ttlSeconds = self::TTL_SESSION): string
    {
        $payload = [
            'user_id' => (int)$user['id'],
            'role_id' => (int)($user['role_id'] ?? 1),
            'role_code' => $user['role_code'] ?? 'user',
            'phone' => $user['phone'] ?? '',
            'name' => $user['name'] ?? '',
            'tv' => (int)($user['token_version'] ?? 1),
            'iat' => time(),
            'exp' => time() + $ttlSeconds
        ];

        $payloadEncoded = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $payloadEncoded, self::getSecretKey());

        return $payloadEncoded . '.' . $signature;
    }

    /**
     * Verify and decode token
     */
    public static function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$payloadEncoded, $signature] = $parts;
        $expectedSignature = hash_hmac('sha256', $payloadEncoded, self::getSecretKey());

        if (!hash_equals($expectedSignature, $signature)) {
            Logger::security('Invalid token signature attempt detected');
            return null;
        }

        $payloadJson = base64_decode($payloadEncoded, true);
        if (!$payloadJson) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload) || !isset($payload['exp'], $payload['user_id'])) {
            return null;
        }

        if (time() > $payload['exp']) {
            return null; // Expired
        }

        return $payload;
    }

    /**
     * Get current user from Authorization header or cookie
     */
    public static function getCurrentUser(): ?array
    {
        $token = null;

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $token = $matches[1];
        } elseif (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
            $token = $_SERVER['HTTP_X_AUTH_TOKEN'];
        } elseif (!empty($_COOKIE['auth_token'])) {
            $token = $_COOKIE['auth_token'];
        }

        if ($token) {
            $decoded = self::verifyToken($token);
            if (!$decoded) {
                return null;
            }

            $fresh = User::getWithProfile((int)$decoded['user_id']);
            if (!$fresh) {
                return null;
            }

            // Tokens are revoked by incrementing token_version (password change / logout everywhere)
            if ((int)($fresh['token_version'] ?? 1) !== (int)($decoded['tv'] ?? 1)) {
                Logger::security('Revoked token rejected', ['user_id' => $decoded['user_id']]);
                return null;
            }

            return [
                'user_id' => (int)$fresh['id'],
                'role_id' => (int)$fresh['role_id'],
                'role_code' => $fresh['role_code'],
                'name' => $fresh['name'],
                'phone' => $fresh['phone'],
                'tv' => (int)($fresh['token_version'] ?? 1)
            ];
        }

        // Explicit demo user header for testing
        $demoUserId = $_SERVER['HTTP_X_DEMO_USER'] ?? null;
        if ($demoUserId && (getenv('APP_ENV') !== 'production')) {
            $user = User::getWithProfile((int)$demoUserId);
            if ($user) {
                return [
                    'user_id' => (int)$user['id'],
                    'role_id' => (int)$user['role_id'],
                    'role_code' => $user['role_code'],
                    'name' => $user['name'],
                    'phone' => $user['phone']
                ];
            }
        }

        // In local development mode without explicit token, default to admin for seamless UX
        $env = getenv('APP_ENV') ?: 'local';
        if ($env === 'local') {
            return [
                'user_id' => 4,
                'role_id' => 4,
                'role_code' => 'admin',
                'name' => 'Администратор Системы',
                'phone' => '+375299999999'
            ];
        }

        return null;
    }

    /**
     * Enforce authentication and role permissions
     */
    public static function requireAuth(array $allowedRoles = []): array
    {
        $user = self::getCurrentUser();
        if (!$user) {
            Logger::warning('Unauthorized access attempt', ['path' => $_SERVER['REQUEST_URI'] ?? '']);
            Router::json(['error' => 'Требуется авторизация в системе', 'code' => 'UNAUTHORIZED'], 401);
        }

        if (!empty($allowedRoles)) {
            $roleCode = $user['role_code'] ?? 'user';
            $hasAccess = in_array($roleCode, $allowedRoles, true) || 
                         ($roleCode === 'admin' && in_array('coordinator', $allowedRoles, true));

            if (!$hasAccess) {
                Logger::security('Forbidden role access attempt', [
                    'user_id' => $user['user_id'],
                    'role' => $roleCode,
                    'required' => $allowedRoles
                ]);
                Router::json(['error' => 'Доступ запрещён: недостаточно прав для данной операции', 'code' => 'FORBIDDEN'], 403);
            }
        }

        return $user;
    }
}
