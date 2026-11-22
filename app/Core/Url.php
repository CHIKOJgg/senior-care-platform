<?php

declare(strict_types=1);

namespace App\Core;

class Url
{
    /**
     * Resolve the public base URL without ever hard-coding a production host.
     * APP_URL is authoritative behind a proxy; otherwise the current request
     * host is used so QR badges still point to the deployment that issued them.
     */
    public static function base(): string
    {
        $configured = trim((string)(getenv('APP_URL') ?: ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '' && preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/', $host)) {
            $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
            $https = $forwardedProto === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            return ($https ? 'https' : 'http') . '://' . $host;
        }

        return 'http://127.0.0.1:8000';
    }

    public static function verifyBadge(string $token): string
    {
        return self::base() . '/verify/' . rawurlencode($token);
    }
}
