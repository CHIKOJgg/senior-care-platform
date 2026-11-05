<?php

declare(strict_types=1);

namespace App\Services;

class SecurityService
{
    private static array $friendlyWords = [
        'Василёк', 'Ромашка', 'Берёза', 'Солнце', 'Каштан',
        'Радуга', 'Рябина', 'Клён', 'Ласточка', 'Колосок',
        'Подснежник', 'Улыбка', 'Ручеёк', 'Янтарь', 'Дубрава'
    ];

    public static function generateSecretCode(): string
    {
        $idx = array_rand(self::$friendlyWords);
        return self::$friendlyWords[$idx];
    }

    /**
     * Normalize secret code:
     * - Trim whitespace
     * - Strip surrounding unicode and standard quotation marks («», "", '', “”, ‘’)
     * - Lowercase (UTF-8)
     * - Cyrillic 'ё' <-> 'е' unification
     */
    public static function normalizeCode(string $code): string
    {
        $code = trim($code);
        $code = (string)preg_replace('/^["\'«»`“”‘’]+|["\'«»`“”‘’]+$/u', '', $code);
        $code = trim($code);
        $code = mb_strtolower($code, 'UTF-8');
        return str_replace('ё', 'е', $code);
    }

    /**
     * Verify provided secret code against expected code with Cyrillic hardening.
     */
    public static function verifyCode(string $provided, string $expected): bool
    {
        $normProvided = self::normalizeCode($provided);
        $normExpected = self::normalizeCode($expected);

        if ($normProvided === '' || $normExpected === '') {
            return false;
        }

        return $normProvided === $normExpected;
    }
}
