<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class CryptoService
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const PREFIX = 'enc:v1:';

    private static ?string $customKey = null;




    public static function setKey(?string $key): void
    {
        self::$customKey = $key;
    }




    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }




    public static function getKey(): string
    {
        if (self::$customKey !== null) {
            return self::normalizeKey(self::$customKey);
        }

        $raw = getenv('APP_ENCRYPTION_KEY') ?: getenv('APP_KEY') ?: '';

        if ($raw === '') {
            $keyFile = __DIR__ . '/../../storage/encryption.key';
            if (file_exists($keyFile)) {
                $raw = trim((string)file_get_contents($keyFile));
            }
        }

        if ($raw === '') {
            $env = getenv('APP_ENV') ?: 'local';
            if ($env === 'production') {
                throw new RuntimeException('ConfigurationException: APP_ENCRYPTION_KEY is required in production');
            }
            $raw = 'senior_care_platform_dev_aes256_master_key_2026';
        }

        return self::normalizeKey($raw);
    }




    public static function normalizeKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
        }

        if (strlen($key) === 64 && ctype_xdigit($key)) {
            $bin = hex2bin($key);
            if ($bin !== false && strlen($bin) === 32) {
                return $bin;
            }
        }

        if (strlen($key) === 32) {
            return $key;
        }

        return hash('sha256', $key, true);
    }





    public static function encrypt(?string $plaintext, string $aad = ''): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return $plaintext;
        }


        if (str_starts_with($plaintext, self::PREFIX)) {
            return $plaintext;
        }

        $key = self::getKey();
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed: ' . (openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        $payload = $iv . $tag . $ciphertext;
        return self::PREFIX . base64_encode($payload);
    }





    public static function decrypt(?string $payload, string $aad = ''): ?string
    {
        if ($payload === null || $payload === '') {
            return $payload;
        }


        if (!str_starts_with($payload, self::PREFIX)) {
            return $payload;
        }

        $rawEncoded = substr($payload, strlen(self::PREFIX));
        $binary = base64_decode($rawEncoded, true);

        if ($binary === false || strlen($binary) < (self::IV_LENGTH + self::TAG_LENGTH)) {
            throw new RuntimeException('DecryptionException: Invalid encrypted payload format');
        }

        $key = self::getKey();
        $iv = substr($binary, 0, self::IV_LENGTH);
        $tag = substr($binary, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($binary, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad
        );

        if ($plaintext === false) {
            throw new RuntimeException('DecryptionException: Authenticated decryption failed (data tampered or invalid key)');
        }

        return $plaintext;
    }




    public static function isEncrypted(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::PREFIX);
    }
}
