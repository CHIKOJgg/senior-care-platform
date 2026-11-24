<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

class SosAlert extends Model
{
    protected static string $table = 'sos_alerts';

    public static function getActiveAlerts(): array
    {
        $sql = "SELECT s.*, u.name as elderly_name, u.phone as elderly_phone,
                       ep.emergency_contact
                FROM sos_alerts s
                JOIN users u ON u.id = s.elderly_id
                LEFT JOIN elderly_profiles ep ON ep.user_id = s.elderly_id
                WHERE s.status IN ('ACTIVE', 'ACKNOWLEDGED')
                ORDER BY s.priority ASC, s.created_at DESC";
        return self::query($sql);
    }

    public static function resolveAlert(int $id): bool
    {
        return self::update($id, [
            'status' => 'RESOLVED',
            'resolved_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Resolve every active signal for one senior in a single action. An incident
     * is per person, so repeated SOS presses must not require repeated clicks.
     */
    public static function resolveAllForElderly(int $elderlyId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE sos_alerts
            SET status = 'RESOLVED', resolved_at = :resolved_at
            WHERE elderly_id = :elderly_id
              AND status IN ('ACTIVE', 'ACKNOWLEDGED')
        ");
        $stmt->execute([
            'resolved_at' => date('Y-m-d H:i:s'),
            'elderly_id' => $elderlyId,
        ]);
        return $stmt->rowCount();
    }
}
