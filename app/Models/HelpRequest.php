<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

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
        $data = ['status' => $status];
        if ($status === 'completed') {
            $data['completed_at'] = date('Y-m-d H:i:s');
        }
        return self::update($id, $data);
    }
}
