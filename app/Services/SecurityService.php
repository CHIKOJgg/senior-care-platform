<?php

namespace App\Services;

class SecurityService
{
    private static array $friendlyWords = [
        'Василёк', 'Ромашка', 'Берёза', 'Солнце', 'Каштан',
        'Радуга', 'Рябина', 'Клён', 'Ласточка', 'Колосок',
        'Подснежник', 'Улыбка', 'Ручеёк', 'Янтарь', 'Дубрава'
    ];

    /**
     * Generate friendly secret verification code for elderly visit safety
     */
    public static function generateSecretCode(): string
    {
        $idx = array_rand(self::$friendlyWords);
        return self::$friendlyWords[$idx];
    }

    public static function verifyCode(string $provided, string $expected): bool
    {
        return mb_strtolower(trim($provided)) === mb_strtolower(trim($expected));
    }
}
