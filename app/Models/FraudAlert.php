<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class FraudAlert extends Model
{
    protected static string $table = 'fraud_alerts';

    public static function getActiveAlerts(): array
    {
        return self::query("SELECT * FROM fraud_alerts WHERE is_active = 1 ORDER BY created_at DESC");
    }

    public static function getAllAlerts(): array
    {
        return self::query("SELECT * FROM fraud_alerts ORDER BY is_active DESC, created_at DESC");
    }

    public static function deactivate(int $id): bool
    {
        return self::update($id, ['is_active' => 0]);
    }
}
