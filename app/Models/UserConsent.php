<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Database;

class UserConsent extends Model
{
    protected static string $table = 'user_consents';

    public static function hasActiveConsent(int $userId, string $type = 'DATA_PROCESSING_99Z'): bool
    {
        $rows = self::where('user_id', $userId);
        foreach ($rows as $r) {
            if ($r['consent_type'] === $type && empty($r['revoked_at'])) {
                return true;
            }
        }
        return false;
    }

    public static function revokeAll(int $userId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE user_consents SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = :uid AND revoked_at IS NULL");
        return $stmt->execute(['uid' => $userId]);
    }
}
