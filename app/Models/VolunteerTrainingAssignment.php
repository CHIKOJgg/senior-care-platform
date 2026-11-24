<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

class VolunteerTrainingAssignment extends Model
{
    protected static string $table = 'volunteer_training_assignments';

    /**
     * Persist a set of retraining materials for a volunteer.
     * Reassigning an existing material refreshes the assignment timestamp but
     * does not create a duplicate row.
     *
     * @param int[] $materialIds
     */
    public static function assign(int $volunteerId, array $materialIds, ?int $assignedBy = null): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $materialIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO volunteer_training_assignments
                (volunteer_id, material_id, assigned_by, assigned_at)
            VALUES (:volunteer_id, :material_id, :assigned_by, :assigned_at)
            ON DUPLICATE KEY UPDATE
                assigned_by = VALUES(assigned_by),
                assigned_at = VALUES(assigned_at)
        ");

        $assigned = 0;
        foreach ($ids as $materialId) {
            $stmt->execute([
                'volunteer_id' => $volunteerId,
                'material_id' => $materialId,
                'assigned_by' => $assignedBy,
                'assigned_at' => date('Y-m-d H:i:s'),
            ]);
            $assigned++;
        }

        return $assigned;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getForVolunteer(int $volunteerId): array
    {
        return self::query("
            SELECT vta.id, vta.volunteer_id, vta.material_id, vta.assigned_by,
                   vta.assigned_at, vta.completed_at,
                   em.title, em.type, em.category, em.content, em.audio_url
            FROM volunteer_training_assignments vta
            JOIN educational_materials em ON em.id = vta.material_id
            WHERE vta.volunteer_id = :volunteer_id
            ORDER BY vta.completed_at IS NOT NULL ASC, vta.assigned_at DESC, em.title ASC
        ", ['volunteer_id' => $volunteerId]);
    }

    /**
     * Mark an assigned material as completed. Returns false when the material
     * was not assigned to this volunteer or was already completed.
     */
    public static function markCompleted(int $volunteerId, int $materialId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE volunteer_training_assignments
            SET completed_at = :completed_at
            WHERE volunteer_id = :volunteer_id
              AND material_id = :material_id
              AND completed_at IS NULL
        ");
        $stmt->execute([
            'completed_at' => date('Y-m-d H:i:s'),
            'volunteer_id' => $volunteerId,
            'material_id' => $materialId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public static function isAssigned(int $volunteerId, int $materialId): bool
    {
        $rows = self::query("
            SELECT 1
            FROM volunteer_training_assignments
            WHERE volunteer_id = :volunteer_id AND material_id = :material_id
            LIMIT 1
        ", ['volunteer_id' => $volunteerId, 'material_id' => $materialId]);
        return $rows !== [];
    }
}
