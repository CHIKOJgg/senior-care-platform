<?php

namespace App\Models;

use App\Core\Model;

class HelpRequest extends Model
{
    protected static string $table = 'help_requests';

    public static function getActiveRequests(): array
    {
        $sql = "SELECT r.*, c.title as category_title, c.icon as category_icon, u.name as elderly_name, u.phone as elderly_phone
                FROM help_requests r
                JOIN categories c ON c.id = r.category_id
                JOIN users u ON u.id = r.elderly_id
                WHERE r.status = 'open'
                ORDER BY r.created_at DESC";
        return self::query($sql);
    }
}
