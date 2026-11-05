<?php

declare(strict_types=1);

namespace App\Services\Identity;

class MsiIdentityAdapter implements IdentityProviderInterface
{
    public const PROVIDER_CODE = 'msi';

    private const REGEX_PATTERN = '/^[1-6]\d{6}[A-Z]\d{3}[A-Z]{2}\d$/';

    private const REGION_MAP = [
        'A' => 'г. Минск',
        'B' => 'Брестская область',
        'C' => 'Витебская область',
        'E' => 'Гомельская область',
        'K' => 'Гродненская область',
        'M' => 'Могилевская область',
        'H' => 'Минская область',
    ];

    private const DOC_TYPE_MAP = [
        'PB' => 'Паспорт гражданина Республики Беларусь',
        'BA' => 'Удостоверение лица без гражданства',
        'BI' => 'Вид на жительство иностранного гражданина в РБ',
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $knownIdentities = [
        '3010190A001PB5' => [
            'first_name' => 'Алексей',
            'last_name' => 'Сергеев',
            'patronymic' => 'Николаевич',
            'gender' => 'M',
            'birth_date' => '1990-01-01',
            'region' => 'г. Минск',
            'document_type' => 'Паспорт гражданина Республики Беларусь',
            'citizenship' => 'BLR',
            'status' => 'VERIFIED',
            'police_clearance_status' => 'CLEAN',
        ],
        '4150885A002PB8' => [
            'first_name' => 'Елена',
            'last_name' => 'Ковалева',
            'patronymic' => 'Владимировна',
            'gender' => 'F',
            'birth_date' => '1985-08-15',
            'region' => 'г. Минск',
            'document_type' => 'Паспорт гражданина Республики Беларусь',
            'citizenship' => 'BLR',
            'status' => 'VERIFIED',
            'police_clearance_status' => 'CLEAN',
        ],
        '3200595A003PB1' => [
            'first_name' => 'Дмитрий',
            'last_name' => 'Новиков',
            'patronymic' => 'Александрович',
            'gender' => 'M',
            'birth_date' => '1995-05-20',
            'region' => 'г. Минск',
            'document_type' => 'Паспорт гражданина Республики Беларусь',
            'citizenship' => 'BLR',
            'status' => 'VERIFIED',
            'police_clearance_status' => 'CLEAN',
        ],
        '3121288B004PB2' => [
            'first_name' => 'Иван',
            'last_name' => 'Баранович',
            'patronymic' => 'Михайлович',
            'gender' => 'M',
            'birth_date' => '1988-12-12',
            'region' => 'Брестская область',
            'document_type' => 'Паспорт гражданина Республики Беларусь',
            'citizenship' => 'BLR',
            'status' => 'VERIFIED',
            'police_clearance_status' => 'CLEAN',
        ],
        '3141188A001PB5' => [
            'first_name' => 'Дмитрий',
            'last_name' => 'Волонтёров',
            'patronymic' => 'Сергеевич',
            'gender' => 'M',
            'birth_date' => '1988-11-14',
            'region' => 'г. Минск',
            'document_type' => 'Паспорт гражданина Республики Беларусь',
            'citizenship' => 'BLR',
            'status' => 'VERIFIED',
            'police_clearance_status' => 'CLEAN',
        ],
        '3010170A999PB0' => [
            'status' => 'SECURITY_ALERT',
            'error_code' => 'MSI_SECURITY_ALERT',
            'message' => 'Лицо заблокировано в системе МСИ / отказ службы безопасности МВД',
        ],
        '3010170A998PB9' => [
            'status' => 'CRIMINAL_RECORD',
            'error_code' => 'POLICE_CLEARANCE_FAILED',
            'message' => 'Имеются сведения о непогашенной судимости, доступ к волонтёрской деятельности запрещён',
        ],
    ];

    public function getProviderName(): string
    {
        return self::PROVIDER_CODE;
    }

    /**
     * Валидация формата и корректности даты личного номера РБ.
     *
     * @return array{valid: bool, error?: string, parsed?: array<string, mixed>}
     */
    public function validateFormat(string $personalNumber): array
    {
        $id = strtoupper(trim($personalNumber));

        if (strlen($id) !== 14) {
            return [
                'valid' => false,
                'error' => 'Длина личного номера должна составлять ровно 14 символов',
            ];
        }

        if (!preg_match(self::REGEX_PATTERN, $id)) {
            return [
                'valid' => false,
                'error' => 'Личный номер не соответствует установленному формату РБ (пример: 3010190A001PB5)',
            ];
        }

        // Парсинг века и пола
        $firstDigit = (int)$id[0];
        $gender = ($firstDigit % 2 === 1) ? 'M' : 'F';
        $centuryBase = match ($firstDigit) {
            1, 2 => 1800,
            3, 4 => 1900,
            5, 6 => 2000,
            default => 1900,
        };

        // Парсинг даты
        $day = (int)substr($id, 1, 2);
        $month = (int)substr($id, 3, 2);
        $yearShort = (int)substr($id, 5, 2);
        $fullYear = $centuryBase + $yearShort;

        if (!checkdate($month, $day, $fullYear)) {
            return [
                'valid' => false,
                'error' => "Некорректная дата рождения в личном номере ({$day}.{$month}.{$fullYear})",
            ];
        }

        $regionCode = $id[7];
        $regionName = self::REGION_MAP[$regionCode] ?? 'Республика Беларусь';

        $docCode = substr($id, 11, 2);
        $docName = self::DOC_TYPE_MAP[$docCode] ?? 'Документ, удостоверяющий личность';

        return [
            'valid' => true,
            'parsed' => [
                'personal_number' => $id,
                'gender' => $gender,
                'birth_date' => sprintf('%04d-%02d-%02d', $fullYear, $month, $day),
                'region_code' => $regionCode,
                'region_name' => $regionName,
                'doc_code' => $docCode,
                'doc_name' => $docName,
            ],
        ];
    }

    /**
     * Маскирование личного номера по Закону РБ № 99-З: 3010190A***PB5
     */
    public function maskPersonalNumber(string $personalNumber): string
    {
        $id = strtoupper(trim($personalNumber));
        if (strlen($id) !== 14) {
            return '**************';
        }
        return substr($id, 0, 8) . '***' . substr($id, 11, 3);
    }

    public function verifyIdentity(string $personalNumber, array $context = []): array
    {
        $id = strtoupper(trim($personalNumber));
        $val = $this->validateFormat($id);

        if (!$val['valid']) {
            return [
                'success' => false,
                'provider' => self::PROVIDER_CODE,
                'verified' => false,
                'error' => 'INVALID_FORMAT',
                'message' => $val['error'] ?? 'Неверный формат идентификационного номера',
            ];
        }

        // Проверка специальных тестовых кейсов с отказом
        if (isset(self::$knownIdentities[$id]) && (self::$knownIdentities[$id]['status'] ?? '') !== 'VERIFIED') {
            $known = self::$knownIdentities[$id];
            return [
                'success' => false,
                'provider' => self::PROVIDER_CODE,
                'verified' => false,
                'error' => $known['error_code'] ?? 'VERIFICATION_DENIED',
                'message' => $known['message'] ?? 'Верификация отклонена системой МСИ',
            ];
        }

        // Поиск известных персон либо динамическая генерация
        $claims = self::$knownIdentities[$id] ?? null;
        if (!$claims) {
            $p = $val['parsed'];
            $claims = [
                'first_name' => ($p['gender'] === 'M') ? 'Алексей' : 'Ольга',
                'last_name' => 'Белорусский',
                'patronymic' => ($p['gender'] === 'M') ? 'Иванович' : 'Ивановна',
                'gender' => $p['gender'],
                'birth_date' => $p['birth_date'],
                'region' => $p['region_name'],
                'document_type' => $p['doc_name'],
                'citizenship' => ($p['doc_code'] === 'PB') ? 'BLR' : 'OTHER',
                'status' => 'VERIFIED',
                'police_clearance_status' => 'CLEAN',
            ];
        }

        $kycToken = 'kyc_msi_' . bin2hex(random_bytes(16));
        $hash = hash('sha256', $id);

        return [
            'success' => true,
            'provider' => self::PROVIDER_CODE,
            'verified' => true,
            'personal_number_masked' => $this->maskPersonalNumber($id),
            'personal_number_hash' => $hash,
            'kyc_token' => $kycToken,
            'verified_at' => date('c'),
            'assurance_level' => 'HIGH',
            'claims' => $claims,
            'context' => $context,
        ];
    }

    public function authenticate(array $credentials): array
    {
        $id = strtoupper(trim((string)($credentials['personal_number'] ?? '')));
        if ($id === '') {
            return [
                'success' => false,
                'provider' => self::PROVIDER_CODE,
                'error' => 'MISSING_CREDENTIALS',
                'message' => 'Не указан идентификационный номер гражданина',
            ];
        }

        $verify = $this->verifyIdentity($id, $credentials);
        if (!$verify['success']) {
            return $verify;
        }

        // Проверка SMS-кода (если передан)
        $smsCode = trim((string)($credentials['sms_code'] ?? ''));
        if ($smsCode !== '' && $smsCode === '0000') {
            return [
                'success' => false,
                'provider' => self::PROVIDER_CODE,
                'error' => 'INVALID_SMS_CODE',
                'message' => 'Неверный одноразовый код подтверждения из SMS',
            ];
        }

        $token = 'msi_tok_' . bin2hex(random_bytes(16));
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
                'id' => 'msi_' . substr(hash('sha256', $id), 0, 16),
                'name' => $fullName,
                'phone' => (string)($credentials['phone'] ?? '+375291112233'),
                'assurance_level' => 'HIGH',
            ],
        ];
    }

    public function fetchProfile(string $token): array
    {
        if (!str_starts_with($token, 'msi_tok_') && !str_starts_with($token, 'kyc_msi_')) {
            return [
                'success' => false,
                'provider' => self::PROVIDER_CODE,
                'error' => 'INVALID_TOKEN',
                'message' => 'Недействительный токен МСИ',
            ];
        }

        return [
            'success' => true,
            'provider' => self::PROVIDER_CODE,
            'assurance_level' => 'HIGH',
            'profile' => self::$knownIdentities['3010190A001PB5'],
        ];
    }
}
