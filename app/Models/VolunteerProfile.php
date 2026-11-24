<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class VolunteerProfile extends Model
{
    protected static string $table = 'volunteer_profiles';
    protected static string $primaryKey = 'user_id';

    public static function getVerified(): array
    {
        return self::query("SELECT vp.*, u.name, u.phone 
                            FROM volunteer_profiles vp 
                            JOIN users u ON u.id = vp.user_id 
                            WHERE vp.is_verified = 1");
    }

    public static function getPending(): array
    {
        return self::query("SELECT vp.*, u.name, u.phone, u.created_at as registered_at 
                            FROM volunteer_profiles vp 
                            JOIN users u ON u.id = vp.user_id 
                            WHERE vp.is_verified = 0 
                            ORDER BY u.created_at DESC");
    }

    public static function getAllWithDetails(): array
    {
        return self::query("SELECT vp.*, u.name, u.phone, u.created_at as registered_at,
                                   (SELECT COUNT(*) FROM volunteer_training_assignments vta
                                    WHERE vta.volunteer_id = vp.user_id) AS training_assigned,
                                   (SELECT COUNT(*) FROM volunteer_training_assignments vta
                                    WHERE vta.volunteer_id = vp.user_id AND vta.completed_at IS NOT NULL) AS training_completed
                            FROM volunteer_profiles vp
                            JOIN users u ON u.id = vp.user_id
                            ORDER BY vp.is_verified ASC, vp.points DESC");
    }

    public static function setVerificationStatus(int $userId, int $status): bool
    {
        return self::update($userId, ['is_verified' => $status]);
    }

    /**
     * Atomically award completion karma so parallel completions cannot overwrite
     * each other's counters.
     */
    public static function addCompletion(int $userId, int $points = 50): bool
    {
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare("
            UPDATE volunteer_profiles
            SET points = COALESCE(points, 0) + :points,
                completed_tasks_count = COALESCE(completed_tasks_count, 0) + 1
            WHERE user_id = :user_id
        ");
        $stmt->execute(['points' => $points, 'user_id' => $userId]);
        return $stmt->rowCount() === 1;
    }
}
