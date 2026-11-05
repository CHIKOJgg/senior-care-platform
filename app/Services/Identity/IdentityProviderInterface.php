<?php

declare(strict_types=1);

namespace App\Services\Identity;

interface IdentityProviderInterface
{
    /**
     * Возвращает уникальный машиночитаемый строковый код провайдера (например, 'msi', 'gosuslugi').
     */
    public function getProviderName(): string;

    /**
     * Аутентификация пользователя по переданным учётным данным или коду авторизации.
     *
     * @param array<string, mixed> $credentials Учётные данные (логин/пароль, токен, СМС-код и т.д.)
     * @return array<string, mixed> Структура с access_token, статусом и данными пользователя
     */
    public function authenticate(array $credentials): array;

    /**
     * Верификация личности по национальному идентификатору (личный номер РБ, СНИЛС и т.д.).
     *
     * @param string $personalNumber Идентификационный номер гражданина
     * @param array<string, mixed> $context Дополнительный контекст/метаданные (телефон, регион и т.д.)
     * @return array<string, mixed> Результат верификации, KYC-токен и проверенные анкетные данные
     */
    public function verifyIdentity(string $personalNumber, array $context = []): array;

    /**
     * Получение расширенного профиля пользователя по выданному токену доступа.
     *
     * @param string $token Токен доступа провайдера
     * @return array<string, mixed> Анкетные данные и уровни доверия
     */
    public function fetchProfile(string $token): array;
}
