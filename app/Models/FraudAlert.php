<?php

namespace App\Models;

use App\Core\Model;

class FraudAlert extends Model
{
    protected static string $table = 'fraud_alerts';

    public static function getActiveAlerts(): array
    {
        return self::query("SELECT * FROM fraud_alerts WHERE is_active = 1 ORDER BY created_at DESC");
    }
}
