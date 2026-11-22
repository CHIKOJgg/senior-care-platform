<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Database;

class HelpRequest extends Model
{
    protected static string $table = 'help_requests';

    public static function getActiveRequests(): array
    {
        $sql = "SELECT r.*, c.title as category_title, c.icon as category_icon, 
                       u.name as elderly_name, u.phone as elderly_phone
                FROM help_requests r
                JOIN categories c ON c.id = r.category_id
                JOIN users u ON u.id = r.elderly_id
                WHERE r.status = 'open'
                ORDER BY r.created_at DESC";
        return self::query($sql);
    }

    public static function getWithDetails(int $id): ?array
    {
        $sql = "SELECT r.*, c.title as category_title, c.icon as category_icon, 
                       u.name as elderly_name, u.phone as elderly_phone,
                       v.name as volunteer_name, v.phone as volunteer_phone
                FROM help_requests r
                JOIN categories c ON c.id = r.category_id
                JOIN users u ON u.id = r.elderly_id
                LEFT JOIN users v ON v.id = r.volunteer_id
                WHERE r.id = :id
                LIMIT 1";
        $res = self::query($sql, ['id' => $id]);
        return $res[0] ?? null;
    }

    public static function getByElderly(int $elderlyId): array
    {
        $sql = "SELECT r.*, c.title as category_title, c.icon as category_icon,
                       v.name as volunteer_name, v.phone as volunteer_phone
                FROM help_requests r
                JOIN categories c ON c.id = r.category_id
                LEFT JOIN users v ON v.id = r.volunteer_id
                WHERE r.elderly_id = :elderly_id
                ORDER BY r.created_at DESC";
        return self::query($sql, ['elderly_id' => $elderlyId]);
    }

    public static function getByVolunteer(int $volunteerId, ?string $status = null): array
    {
        $params = ['volunteer_id' => $volunteerId];
        $statusSql = '';
        if ($status !== null && $status !== 'all' && $status !== '') {
            $statusSql = ' AND r.status = :status';
            $params['status'] = $status;
        }

        $sql = "SELECT r.*, c.title as category_title, c.icon as category_icon,
                       u.name as elderly_name, u.phone as elderly_phone
                FROM help_requests r
                JOIN categories c ON c.id = r.category_id
                JOIN users u ON u.id = r.elderly_id
                WHERE r.volunteer_id = :volunteer_id {$statusSql}
                ORDER BY r.created_at DESC";
        return self::query($sql, $params);
    }

    public static function getAllWithDetails(?string $status = null): array
    {
        $params = [];
        $where = "";
        if ($status !== null && $status !== 'all' && $status !== '') {
            $where = "WHERE r.status = :status";
            $params['status'] = $status;
        }

        $sql = "SELECT r.*, c.title as category_title, c.icon as category_icon, 
                       u.name as elderly_name, u.phone as elderly_phone,
                       v.name as volunteer_name, v.phone as volunteer_phone
                FROM help_requests r
                JOIN categories c ON c.id = r.category_id
                JOIN users u ON u.id = r.elderly_id
                LEFT JOIN users v ON v.id = r.volunteer_id
                {$where}
                ORDER BY r.created_at DESC";
        return self::query($sql, $params);
    }

    public static function updateStatus(int $id, string $status): bool
    {
        $data = ['status' => $status, 'completed_at' => $status === 'completed' ? date('Y-m-d H:i:s') : null];
        if ($status !== 'in_progress') {
            $data['is_overdue'] = 0;
        }
        return self::update($id, $data);
    }

    /**
     * Atomically claim an open request for a volunteer.
     *
     * The WHERE status = 'open' predicate makes the operation safe when two
     * volunteers press "accept" at the same time: exactly one UPDATE wins.
     */
    public static function tryAccept(int $id, int $volunteerId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE help_requests
            SET volunteer_id = :volunteer_id,
                status = 'in_progress',
                assigned_at = :assigned_at,
                is_overdue = 0,
                failed_attempts = 0
            WHERE id = :id AND status = 'open'
        ");
        $stmt->execute([
            'volunteer_id' => $volunteerId,
            'assigned_at' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Atomically record one wrong secret-word attempt and return the new count.
     * Concurrent guesses cannot race past the lock threshold.
     */
    public static function registerFailedAttempt(int $id, int $max = 5): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE help_requests
            SET failed_attempts = COALESCE(failed_attempts, 0) + 1
            WHERE id = :id
              AND status = 'in_progress'
              AND COALESCE(failed_attempts, 0) < :max
        ");
        $stmt->execute(['id' => $id, 'max' => $max]);

        $read = $db->prepare('SELECT failed_attempts FROM help_requests WHERE id = :id');
        $read->execute(['id' => $id]);
        return (int)($read->fetchColumn() ?: 0);
    }

    /**
     * Atomically finish an in-progress request.
     *
     * A nullable volunteer id means "coordinator/admin override"; when it is
     * provided the request must still belong to that volunteer. This guards
     * against double completion (and double karma awards) under races/replays.
     */
    public static function completeIfInProgress(
        int $id,
        ?int $volunteerId,
        float $lat,
        float $lon,
        float $distanceToTarget
    ): bool {
        $db = Database::getConnection();
        $sql = "
            UPDATE help_requests
            SET status = 'completed',
                completed_at = :completed_at,
                failed_attempts = 0,
                is_overdue = 0,
                completed_lat = :lat,
                completed_lon = :lon,
                distance_to_target = :distance
            WHERE id = :id AND status = 'in_progress'
        ";
        $params = [
            'completed_at' => date('Y-m-d H:i:s'),
            'lat' => $lat,
            'lon' => $lon,
            'distance' => $distanceToTarget,
            'id' => $id,
        ];
        if ($volunteerId !== null) {
            $sql .= ' AND volunteer_id = :volunteer_id';
            $params['volunteer_id'] = $volunteerId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    }
}
