<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Models\User;
use Throwable;

/**
 * Coordinator-assisted password recovery (Law 99-Z friendly):
 * the coordinator issues a one-time 6-digit code, the family sets a new password.
 */
class PasswordResetService
{
    public const TTL_SECONDS = 1800;
    public const MAX_ATTEMPTS = 5;
    private const CODE_LENGTH = 6;
    private const GENERIC_ERROR = 'Неверный телефон или код подтверждения';

    public static function createCode(int $userId, int $createdBy): array
    {
        $pdo = Database::getConnection();

        // Only one active code per user
        $pdo->prepare("UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE user_id = :uid AND used_at IS NULL")
            ->execute(['uid' => $userId]);

        $code = str_pad((string)random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TTL_SECONDS);

        $pdo->prepare("
            INSERT INTO password_resets (user_id, code_hash, expires_at, created_by)
            VALUES (:uid, :hash, :exp, :by)
        ")->execute([
            'uid' => $userId,
            'hash' => password_hash($code, PASSWORD_DEFAULT),
            'exp' => $expiresAt,
            'by' => $createdBy
        ]);

        Logger::security('Password reset code issued', [
            'user_id' => $userId,
            'issued_by' => $createdBy
        ]);

        return ['code' => $code, 'expires_in' => self::TTL_SECONDS];
    }

    public static function resetPassword(string $phone, string $code, string $newPassword): array
    {
        $user = User::findByPhone(trim($phone));
        if (!$user) {
            return ['success' => false, 'error' => self::GENERIC_ERROR];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT * FROM password_resets
            WHERE user_id = :uid AND used_at IS NULL
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['uid' => $user['id']]);
        $reset = $stmt->fetch();

        if (!$reset) {
            return ['success' => false, 'error' => self::GENERIC_ERROR];
        }

        if (strtotime($reset['expires_at']) < time()) {
            $pdo->prepare("UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute(['id' => $reset['id']]);
            return ['success' => false, 'error' => 'Код истёк. Попросите координатора выдать новый.'];
        }

        if ((int)$reset['attempts'] >= self::MAX_ATTEMPTS) {
            $pdo->prepare("UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute(['id' => $reset['id']]);
            return ['success' => false, 'error' => 'Код заблокирован после нескольких ошибок. Попросите новый.'];
        }

        if (!password_verify($code, $reset['code_hash'])) {
            $pdo->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id = :id")
                ->execute(['id' => $reset['id']]);
            Logger::warning('Failed password reset attempt', ['user_id' => $user['id']]);
            return ['success' => false, 'error' => self::GENERIC_ERROR];
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute(['id' => $reset['id']]);
            $pdo->prepare("
                UPDATE users
                SET password_hash = :hash, token_version = COALESCE(token_version, 1) + 1
                WHERE id = :id
            ")->execute([
                'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => $user['id']
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Logger::security('Password reset completed', ['user_id' => $user['id']]);

        return ['success' => true];
    }
}
