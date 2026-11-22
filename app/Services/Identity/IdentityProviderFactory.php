<?php

declare(strict_types=1);

namespace App\Services\Identity;

use InvalidArgumentException;

class IdentityProviderFactory
{
    /**
     * Создать экземпляр адаптера по строковому коду.
     */
    public static function get(string $providerName): IdentityProviderInterface
    {
        $code = strtolower(trim($providerName));

        return match ($code) {
            'msi', 'msi_belarus', 'belarus', 'msi_by' => new MsiIdentityAdapter(),
            'gosuslugi', 'esia', 'russia', 'gosuslugi_ru' => new GosuslugiIdentityAdapter(),
            default => throw new InvalidArgumentException("Неизвестный провайдер идентификации: '{$providerName}'. Доступны: 'msi', 'gosuslugi'"),
        };
    }

    /**
     * Реестр поддерживаемых провайдеров идентификации с метаданными.
     *
     * @return array<int, array<string, string>>
     */
    public static function getAvailableProviders(): array
    {
        return [
            [
                'code' => 'msi',
                'name' => 'МСИ Республики Беларусь',
                'description' => 'Межбанковская система идентификации (по 14-значному личному номеру паспорта гражданина РБ)',
                'national_id_label' => 'Идентификационный номер паспорта',
                'placeholder' => '3010190A001PB5',
            ],
            [
                'code' => 'gosuslugi',
                'name' => 'Госуслуги (ЕСИА РФ)',
                'description' => 'Единая система идентификации и аутентификации (по номеру СНИЛС)',
                'national_id_label' => 'СНИЛС',
                'placeholder' => '112-233-445 95',
            ],
        ];
    }
}
