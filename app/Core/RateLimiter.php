<?php

declare(strict_types=1);

namespace App\Core;

class RateLimiter
{
    private static string $cacheDir = __DIR__ . '/../../storage/cache/rate_limits';

    public static function check(string $key, int $maxAttempts = 5, int $decaySeconds = 60): bool
    {
        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0775, true);
        }

        $hash = md5($key);
        $file = self::$cacheDir . '/' . $hash . '.json';
        $now = time();

        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file) ?: '', true);
            if (is_array($data)) {
                $expiresAt = $data['expires_at'] ?? 0;
                $attempts = (int)($data['attempts'] ?? 0);

                if ($now < $expiresAt) {
                    if ($attempts >= $maxAttempts) {
                        return false; // Rate limit exceeded!
                    }
                    $data['attempts'] = $attempts + 1;
                    file_put_contents($file, json_encode($data), LOCK_EX);
                    return true;
                }
            }
        }

        // Fresh or expired window
        $data = [
            'attempts' => 1,
            'expires_at' => $now + $decaySeconds
        ];
        file_put_contents($file, json_encode($data), LOCK_EX);
        return true;
    }

    public static function clear(string $key): void
    {
        $hash = md5($key);
        $file = self::$cacheDir . '/' . $hash . '.json';
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}
