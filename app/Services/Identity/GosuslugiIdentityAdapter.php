<?php

declare(strict_types=1);

namespace App\Services\Identity;

class GosuslugiIdentityAdapter implements IdentityProviderInterface
{
    public const PROVIDER_CODE = 'gosuslugi';

    private const SNILS_REGEX = '/^(?:\d{3}-\d{3}-\d{3}\s\d{2}|\d{11})$/';

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $knownIdentities = [
        '11223344595' => [
            'first_name' => 'Екатерина',
            'last_name' => 'Смирнова',
            'patronymic' => 'Сергеевна',
            'gender' => 'F',
            'birth_date' => '1992-04-10',
            'citizenship' => 'RUS',
            'status' => 'CONFIRMED',
            'assurance_level' => 'CONFIRMED_ACCOUNT',
        ],
        '12345678964' => [
            'first_name' => 'Михаил',
            'last_name' => 'Кузнецов',
            'patronymic' => 'Иванович',
            'gender' => 'M',
            'birth_date' => '1988-11-25',
            'citizenship' => 'RUS',
            'status' => 'CONFIRMED',
            'assurance_level' => 'CONFIRMED_ACCOUNT',
        ],
        '00000000000' => [
            'status' => 'UNCONFIRMED',
            'error_code' => 'ESIA_UNCONFIRMED_ACCOUNT',
            'message' => 'Учётная запись Госуслуг не имеет статуса подтверждённой',
        ],
        '99999999999' => [
            'status' => 'BLOCKED',
            'error_code' => 'ESIA_ACCOUNT_BLOCKED',
            'message' => 'Учётная запись ЕСИА временно заблокирована',
        ],
    ];

    public function getProviderName(): string
    {
        return self::PROVIDER_CODE;
    }

    /**
     * Валидация СНИЛС с вычислением контрольной суммы.
     *
     * @return array{valid: bool, error?: string, clean_snils?: string}
     */
    public function validateSnils(string $snils): array
    {
        $trimmed = trim($snils);

        if (!preg_match(self::SNILS_REGEX, $trimmed)) {
            return [
                'valid' => false,
                'error' => 'СНИЛС должен содержать 11 цифр (формат 112-233-445 95 или 11223344595)',
            ];
        }

        $digits = preg_replace('/\D/', '', $trimmed);
        if (strlen($digits) !== 11) {
            return [
                'valid' => false,
                'error' => 'СНИЛС должен содержать ровно 11 цифр',
            ];
        }

        // Пропуск специальных тестовых мок-номеров
        if (isset(self::$knownIdentities[$digits])) {
            return ['valid' => true, 'clean_snils' => $digits];
        }

        // Число меньше либо равно 001-001-998 не проверяется по контрольной сумме
        $numPart = (int)substr($digits, 0, 9);
        if ($numPart <= 1001998) {
            return ['valid' => true, 'clean_snils' => $digits];
        }

        // Расчёт контрольной суммы
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int)$digits[$i] * (9 - $i);
        }

        $checkDigit = 0;
        if ($sum < 100) {
            $checkDigit = $sum;
        } elseif ($sum === 100 || $sum === 101) {
            $checkDigit = 0;
        } else {
            $rem = $sum % 101;
            $checkDigit = ($rem < 100) ? $rem : 0;
        }

        $expectedCheck = (int)substr($digits, 9, 2);
        if ($checkDigit !== $expectedCheck) {
            return [
                'valid' => false,
                'error' => sprintf('Неверная контрольная сумма СНИЛС (ожидается %02d, получено %02d)', $checkDigit, $expectedCheck),
            ];
        }

        return ['valid' => true, 'clean_snils' => $digits];
    }

    /**
     * Маскирование СНИЛС: ***-***-445 95
     */
    public function maskSnils(string $snils): string
    {
        $digits = preg_replace('/\D/', '', trim($snils));
        if (strlen($digits) !== 11) {
            return '***-***-*** **';
        }
        $p3 = substr($digits, 6, 3);
        $p4 = substr($digits, 9, 2);
        return "***-***-{$p3} {$p4}";
    }

    public function verifyIdentity(string $personalNumber, array $context = []): array
    {
        $val = $this->validateSnils($personalNumber);
        if (!$val['valid']) {
            return [
                'success' => false,
                'provider' => self::PROVIDER_CODE,
                'verified' => false,
                'error' => 'INVALID_SNILS',
                'message' => $val['error'] ?? 'Некорректный номер СНИЛС',
            ];
        }

        $clean = $val['clean_snils'];

        // Отработка тестовых отказов
        if (isset(self::$knownIdentities[$clean]) && (self::$knownIdentities[$clean]['status'] ?? '') !== 'CONFIRMED') {
            $known = self::$knownIdentities[$clean];
            return [
                'success' => false,
                'provider' => self::PROVIDER_CODE,
                'verified' => false,
                'error' => $known['error_code'] ?? 'ESIA_REJECTED',
                'message' => $known['message'] ?? 'Верификация отклонена ЕСИА',
            ];
        }

        $claims = self::$knownIdentities[$clean] ?? [
            'first_name' => 'Михаил',
            'last_name' => 'Волонтёров',
            'patronymic' => 'Александрович',
            'gender' => 'M',
            'birth_date' => '1991-07-14',
            'citizenship' => 'RUS',
            'status' => 'CONFIRMED',
            'assurance_level' => 'CONFIRMED_ACCOUNT',
        ];

        $kycToken = 'kyc_esia_' . bin2hex(random_bytes(16));
        $hash = hash('sha256', $clean);

        return [
            'success' => true,
            'provider' => self::PROVIDER_CODE,
            'verified' => true,
            'personal_number_masked' => $this->maskSnils($clean),
            'personal_number_hash' => $hash,
            'kyc_token' => $kycToken,
            'verified_at' => date('c'),
            'assurance_level' => 'CONFIRMED_ACCOUNT',
            'claims' => $claims,
            'context' => $context,
        ];
    }

    public function authenticate(array $credentials): array
    {
        $snils = (string)($credentials['snils'] ?? $credentials['login'] ?? '');
        if ($snils === '') {
            return [
                'success' => false,
                'provider' => self::PROVIDER_CODE,
                'error' => 'MISSING_CREDENTIALS',
                'message' => 'Не указан СНИЛС или логин Госуслуг',
            ];
        }

        $verify = $this->verifyIdentity($snils, $credentials);
        if (!$verify['success']) {
            return $verify;
        }

        $token = 'esia_tok_' . bin2hex(random_bytes(16));
        $claims = $verify['claims'];
        $fullName = trim("{$claims['last_name']} {$claims['first_name']} {$claims['patronymic']}");

        return [
            'success' => true,
            'provider' => self::PROVIDER_CODE,
            'access_token' => $token,
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'personal_number_masked' => $verify['personal_number_masked'],
            'user' => [
                'id' => 'esia_' . substr(hash('sha256', $snils), 0, 16),
                'name' => $fullName,
                'phone' => (string)($credentials['phone'] ?? '+79161234567'),
                'assurance_level' => 'CONFIRMED_ACCOUNT',
            ],
        ];
    }

    public function fetchProfile(string $token): array
    {
        if (!str_starts_with($token, 'esia_tok_') && !str_starts_with($token, 'kyc_esia_')) {
            return [
                'success' => false,
                'provider' => self::PROVIDER_CODE,
                'error' => 'INVALID_TOKEN',
                'message' => 'Недействительный токен ЕСИА',
            ];
        }

        return [
            'success' => true,
            'provider' => self::PROVIDER_CODE,
            'assurance_level' => 'CONFIRMED_ACCOUNT',
            'profile' => self::$knownIdentities['11223344595'],
        ];
    }
}
