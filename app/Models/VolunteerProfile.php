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
     * Recalculate the volunteer's average rating from completed requests and
     * apply the low-rating restriction when it drops below 3.0.
     *
     * @return array{rating: float, rating_count: int, is_restricted: bool}
     */
    public static function recalculateRating(int $userId): array
    {
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare("
            SELECT AVG(rating_score) AS avg_rating, COUNT(*) AS rating_count
            FROM help_requests
            WHERE volunteer_id = :user_id AND rating_score IS NOT NULL
        ");
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $ratingCount = (int)($row['rating_count'] ?? 0);

        if ($ratingCount === 0) {
            $profile = self::find($userId);
            return [
                'rating' => (float)($profile['rating'] ?? 5.0),
                'rating_count' => 0,
                'is_restricted' => (int)($profile['is_restricted'] ?? 0) === 1,
            ];
        }

        $average = round((float)$row['avg_rating'], 2);
        $isLow = $average < 3.0;
        $profile = self::find($userId);
        $update = ['rating' => $average];
        if ($isLow) {
            $update['is_restricted'] = 1;
            $update['restriction_reason'] = 'Низкий рейтинг: требуется реабилитация и проверка координатора.';
            if (empty($profile['restricted_at'] ?? null)) {
                $update['restricted_at'] = date('Y-m-d H:i:s');
            }
        }
        self::update($userId, $update);

        return [
            'rating' => $average,
            'rating_count' => $ratingCount,
            'is_restricted' => $isLow || (int)($profile['is_restricted'] ?? 0) === 1,
        ];
    }

    public static function unrestrict(int $userId): bool
    {
        $profile = self::find($userId);
        $rating = max((float)($profile['rating'] ?? 5.0), 3.0);
        return self::update($userId, [
            'is_restricted' => 0,
            'restriction_reason' => null,
            'restricted_at' => null,
            // A coordinator review restores the minimum allowed rating so the
            // legacy low score cannot silently keep blocking new tasks.
            'rating' => $rating,
        ]);
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
