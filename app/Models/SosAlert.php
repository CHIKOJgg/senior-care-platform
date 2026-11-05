<?php

declare(strict_types=1);

namespace App\Models;

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
}
