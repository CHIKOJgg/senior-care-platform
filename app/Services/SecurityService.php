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
        return self::$friendlyWords[random_int(0, count(self::$friendlyWords) - 1)];
    }








    public static function normalizeCode(string $code): string
    {
        $code = trim($code);
        $code = (string)preg_replace('/^["\'«»`“”‘’]+|["\'«»`“”‘’]+$/u', '', $code);
        $code = trim($code);
        $code = mb_strtolower($code, 'UTF-8');
        return str_replace('ё', 'е', $code);
    }




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
