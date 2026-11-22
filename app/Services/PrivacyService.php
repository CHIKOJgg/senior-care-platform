<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use InvalidArgumentException;
use DomainException;
use Throwable;
use PDO;

require_once __DIR__ . '/RegionService.php';

class PrivacyService
{
    // Текущая актуальная версия Политики обработки персональных данных ТЦСОН
    public const CURRENT_POLICY_VERSION = '1.0.0';

    // Поддерживаемые регуляторные типы согласий (Закон РБ № 99-З)
    public const CONSENT_DATA_PROCESSING = 'DATA_PROCESSING_99Z';
    public const CONSENT_HEALTH_DATA     = 'SENSITIVE_HEALTH_DATA';
    public const CONSENT_GEOLOCATION     = 'GEOLOCATION_TRACKING';

    // Гео-загрубление по всей стране: координаты берутся из региона пользователя.
    public const SOVETSKY_CENTROID_LAT = 53.9168;
    public const SOVETSKY_CENTROID_LON = 27.5862;
    public const ANONYMIZED_ADDRESS    = 'Республика Беларусь';

    /**
     * Supported consent types list
     */
    public static function getSupportedConsentTypes(): array
    {
        return [
            self::CONSENT_DATA_PROCESSING,
            self::CONSENT_HEALTH_DATA,
            self::CONSENT_GEOLOCATION,
        ];
    }

    /**
     * Record user consent in user_consents ledger
     */
    public function recordConsent(
        int $userId,
        string $consentType,
        string $policyVersion = self::CURRENT_POLICY_VERSION,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        if (!in_array($consentType, self::getSupportedConsentTypes(), true)) {
            throw new InvalidArgumentException("Недопустимый тип согласия: {$consentType}. Допустимые: " . implode(', ', self::getSupportedConsentTypes()));
        }

        $ip = $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $ua = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
        $ip = mb_substr((string)$ip, 0, 45, 'UTF-8');
        $ua = mb_substr((string)$ua, 0, 1000, 'UTF-8');
        $policyVersion = mb_substr($policyVersion, 0, 50, 'UTF-8');

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO user_consents (user_id, consent_type, policy_version, ip_address, user_agent, created_at)
            VALUES (:user_id, :consent_type, :policy_version, :ip_address, :user_agent, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'consent_type' => $consentType,
            'policy_version' => $policyVersion,
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);

        $insertId = (int)$pdo->lastInsertId();
        Logger::info("Electronic consent [{$consentType}] recorded for user #{$userId} (policy v{$policyVersion})");

        return [
            'id' => $insertId,
            'user_id' => $userId,
            'consent_type' => $consentType,
            'policy_version' => $policyVersion,
            'ip_address' => $ip,
            'created_at' => date('Y-m-d H:i:s'),
            'is_active' => true,
        ];
    }

    /**
     * Get user consent history
     */
    public function getConsents(int $userId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT id, user_id, consent_type, policy_version, ip_address, user_agent, created_at, revoked_at
            FROM user_consents
            WHERE user_id = :user_id
            ORDER BY id DESC
        ");
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['user_id'] = (int)$r['user_id'];
            $r['is_active'] = empty($r['revoked_at']);
        }
        unset($r);

        return $rows;
    }

    /**
     * Check if user has active consent with optional minimum policy version
     */
    public function hasActiveConsent(int $userId, string $consentType, ?string $minVersion = null): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT policy_version, revoked_at
            FROM user_consents
            WHERE user_id = :user_id AND consent_type = :consent_type AND revoked_at IS NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'consent_type' => $consentType,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        if ($minVersion !== null) {
            return version_compare((string)$row['policy_version'], $minVersion, '>=');
        }

        return true;
    }

    /**
     * Revoke specific consent
     */
    public function revokeConsent(int $userId, string $consentType): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            UPDATE user_consents 
            SET revoked_at = CURRENT_TIMESTAMP 
            WHERE user_id = :user_id AND consent_type = :consent_type AND revoked_at IS NULL
        ");
        $stmt->execute([
            'user_id' => $userId,
            'consent_type' => $consentType,
        ]);

        $affected = $stmt->rowCount();
        if ($affected > 0) {
            Logger::info("Consent [{$consentType}] revoked for user #{$userId}");
            return true;
        }

        return false;
    }

    /**
     * Pre-check whether user can be anonymized (Right to be Forgotten)
     */
    public function canAnonymizeUser(int $userId): array
    {
        $pdo = Database::getConnection();

        $stmtUser = $pdo->prepare("SELECT id FROM users WHERE id = :id");
        $stmtUser->execute(['id' => $userId]);
        if (!$stmtUser->fetch()) {
            return [
                'allowed' => false,
                'reason' => 'Пользователь не найден',
                'active_requests_count' => 0
            ];
        }

        $stmtReq = $pdo->prepare("
            SELECT COUNT(*) as cnt
            FROM help_requests
            WHERE (elderly_id = :elderly_id OR volunteer_id = :volunteer_id)
              AND status IN ('open', 'in_progress')
        ");
        $stmtReq->execute(['elderly_id' => $userId, 'volunteer_id' => $userId]);
        $cnt = (int)$stmtReq->fetchColumn();

        if ($cnt > 0) {
            return [
                'allowed' => false,
                'reason' => "Невозможно обезличить аккаунт: имеются активные заявки в количестве {$cnt} шт. Завершите или отмените их перед удалением ПД.",
                'active_requests_count' => $cnt
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'active_requests_count' => 0
        ];
    }

    /**
     * Generate unique anonymized phone placeholder satisfying users.phone UNIQUE constraint
     */
    public static function generateAnonymousPhone(int $userId): string
    {
        if ($userId < 10000) {
            return '+375 (00) 000-' . sprintf('%02d-%02d', intdiv($userId, 100), $userId % 100);
        }
        return '+375 (00) 000-' . sprintf('%d-%02d', intdiv($userId, 100), $userId % 100);
    }

    /**
     * Cryptographically anonymize user data per Law 99-Z Art. 11
     * Preserves foreign keys and civic reporting while wiping personal data
     */
    public function anonymizeUser(int $userId, ?string $reason = 'RIGHT_TO_BE_FORGOTTEN_99Z'): array
    {
        $check = $this->canAnonymizeUser($userId);
        if (!$check['allowed']) {
            throw new DomainException($check['reason'] ?? 'Обезличивание заблокировано');
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $anonPhone = self::generateAnonymousPhone($userId);
            $anonName = "Анонимизированный пользователь #{$userId}";
            $unmatchableHash = 'ANON_' . bin2hex(random_bytes(16));

            // Country-wide: coarsen coordinates to the user's regional centre.
            $regionCode = 'MINSK_CITY';
            try {
                $regionStmt = $pdo->prepare("SELECT region_code FROM elderly_profiles WHERE user_id = :id");
                $regionStmt->execute(['id' => $userId]);
                $foundRegion = $regionStmt->fetchColumn();
                if (!$foundRegion) {
                    $regionStmt = $pdo->prepare("SELECT region_code FROM volunteer_profiles WHERE user_id = :id");
                    $regionStmt->execute(['id' => $userId]);
                    $foundRegion = $regionStmt->fetchColumn();
                }
                if ($foundRegion) {
                    $regionCode = (string)$foundRegion;
                }
            } catch (\Throwable $e) {
                $regionCode = 'MINSK_CITY';
            }
            $regionCenter = RegionService::center($regionCode);

            // 1. Update users table
            $stmtUser = $pdo->prepare("
                UPDATE users 
                SET name = :name, phone = :phone, password_hash = :hash,
                    token_version = COALESCE(token_version, 1) + 1
                WHERE id = :id
            ");
            $stmtUser->execute([
                'name' => $anonName,
                'phone' => $anonPhone,
                'hash' => $unmatchableHash,
                'id' => $userId
            ]);

            // 2. Wipe sensitive health data & coarsen location in elderly_profiles
            $stmtElderly = $pdo->prepare("
                UPDATE elderly_profiles 
                SET address = :address,
                    latitude = :lat,
                    longitude = :lon,
                    mobility_notes = NULL,
                    emergency_contact = NULL,
                    birth_year = NULL
                WHERE user_id = :id
            ");
            $stmtElderly->execute([
                'address' => self::ANONYMIZED_ADDRESS,
                'lat' => $regionCenter['lat'],
                'lon' => $regionCenter['lon'],
                'id' => $userId
            ]);

            // 3. Wipe details in volunteer_profiles if volunteer
            $stmtVol = $pdo->prepare("
                UPDATE volunteer_profiles 
                SET is_verified = 0,
                    verification_status = 'REVOKED',
                    badge_token = NULL,
                    skills = NULL,
                    latitude = :lat,
                    longitude = :lon,
                    passport_masked = NULL,
                    police_clearance_status = 'NOT_SUBMITTED',
                    id_provider = NULL,
                    id_provider_user_id = NULL
                WHERE user_id = :id
            ");
            $stmtVol->execute([
                'lat' => $regionCenter['lat'],
                'lon' => $regionCenter['lon'],
                'id' => $userId
            ]);

            // 4. Sanitize history in help_requests (preserve record count and foreign keys, wipe free text)
            $stmtReq = $pdo->prepare("
                UPDATE help_requests 
                SET address = :address,
                    title = '[ОБЕЗЛИЧЕНО]',
                    description = NULL,
                    volunteer_feedback = NULL,
                    secret_code = '[REDACTED]'
                WHERE elderly_id = :elderly_id OR volunteer_id = :volunteer_id
            ");
            $stmtReq->execute([
                'address' => self::ANONYMIZED_ADDRESS,
                'elderly_id' => $userId,
                'volunteer_id' => $userId
            ]);

            // 4b. Remove conversation content tied to the user or their requests.
            $stmtChat = $pdo->prepare("
                DELETE FROM chat_messages
                WHERE sender_id = :sender_id
                   OR request_id IN (SELECT id FROM help_requests WHERE elderly_id = :elderly_id OR volunteer_id = :volunteer_id)
            ");
            $stmtChat->execute([
                'sender_id' => $userId,
                'elderly_id' => $userId,
                'volunteer_id' => $userId
            ]);

            // 4c. Invalidate any outstanding recovery codes.
            $stmtResets = $pdo->prepare("
                UPDATE password_resets SET used_at = CURRENT_TIMESTAMP
                WHERE user_id = :id AND used_at IS NULL
            ");
            $stmtResets->execute(['id' => $userId]);

            // 5. Sanitize sos_alerts history if any
            $stmtSos = $pdo->prepare("
                UPDATE sos_alerts 
                SET address = :address,
                    latitude = :lat,
                    longitude = :lon,
                    reason = NULL
                WHERE elderly_id = :id
            ");
            $stmtSos->execute([
                'address' => self::ANONYMIZED_ADDRESS,
                'lat' => $regionCenter['lat'],
                'lon' => $regionCenter['lon'],
                'id' => $userId
            ]);

            // 6. Revoke active consents and record revocation entry
            $stmtRevoke = $pdo->prepare("
                UPDATE user_consents 
                SET revoked_at = CURRENT_TIMESTAMP 
                WHERE user_id = :id AND revoked_at IS NULL
            ");
            $stmtRevoke->execute(['id' => $userId]);

            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            $stmtAudit = $pdo->prepare("
                INSERT INTO user_consents (user_id, consent_type, policy_version, ip_address, user_agent, created_at, revoked_at)
                VALUES (:id, 'REVOCATION_RIGHT_TO_BE_FORGOTTEN', :policy_version, :ip, :ua, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmtAudit->execute([
                'id' => $userId,
                'policy_version' => self::CURRENT_POLICY_VERSION,
                'ip' => $ip,
                'ua' => $ua
            ]);

            $pdo->commit();
            Logger::security("User #{$userId} personal data successfully anonymized per Law 99-Z");

            return [
                'success' => true,
                'user_id' => $userId,
                'pseudonym' => $anonName,
                'anonymized_at' => date('Y-m-d H:i:s'),
                'message' => 'Персональные данные успешно обезличены в соответствии с Законом РБ № 99-З'
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
