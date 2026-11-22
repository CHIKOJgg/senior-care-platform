<?php

declare(strict_types=1);

namespace App\Core;

class RateLimiter
{
    private static string $cacheDir = __DIR__ . '/../../storage/cache/rate_limits';

    /**
     * Atomically consume one attempt from a fixed window counter.
     *
     * The counter file is opened once and guarded with an exclusive flock so that
     * concurrent PHP-FPM workers cannot race the read-modify-write sequence and
     * accidentally let a burst through (or lose attempts).
     */
    public static function check(string $key, int $maxAttempts = 5, int $decaySeconds = 60): bool
    {
        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0775, true);
        }

        $file = self::$cacheDir . '/' . md5($key) . '.json';
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            // Fail open: a broken cache must not take the platform down.
            return true;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $data = json_decode((string)$raw, true);
            $now = time();

            $expiresAt = is_array($data) ? (int)($data['expires_at'] ?? 0) : 0;
            $attempts = is_array($data) ? (int)($data['attempts'] ?? 0) : 0;

            if ($now >= $expiresAt) {
                $attempts = 0;
                $expiresAt = $now + $decaySeconds;
            }

            if ($attempts >= $maxAttempts) {
                return false;
            }

            $payload = json_encode([
                'attempts' => $attempts + 1,
                'expires_at' => $expiresAt,
            ]);

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string)$payload);
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function clear(string $key): void
    {
        $file = self::$cacheDir . '/' . md5($key) . '.json';
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Remove every rate-limit counter. Intended for test runners and maintenance
     * commands; never call this from an HTTP endpoint.
     */
    public static function clearAll(): void
    {
        foreach (glob(self::$cacheDir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }
}
