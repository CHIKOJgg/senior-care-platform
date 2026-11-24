<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class FraudAlert extends Model
{
    protected static string $table = 'fraud_alerts';

    public static function getActiveAlerts(?string $region = null): array
    {
        if ($region === null) {
            return self::query("SELECT * FROM fraud_alerts WHERE is_active = 1 ORDER BY created_at DESC");
        }
        return self::query(
            "SELECT * FROM fraud_alerts
             WHERE is_active = 1
               AND (region_code IS NULL OR region_code = '' OR region_code = :region)
             ORDER BY created_at DESC",
            ['region' => $region]
        );
    }

    public static function getAllAlerts(?string $region = null): array
    {
        if ($region === null) {
            return self::query("SELECT * FROM fraud_alerts ORDER BY is_active DESC, created_at DESC");
        }
        return self::query(
            "SELECT * FROM fraud_alerts
             WHERE (region_code IS NULL OR region_code = '' OR region_code = :region)
             ORDER BY is_active DESC, created_at DESC",
            ['region' => $region]
        );
    }

    public static function deactivate(int $id): bool
    {
        return self::update($id, ['is_active' => 0]);
    }
}
