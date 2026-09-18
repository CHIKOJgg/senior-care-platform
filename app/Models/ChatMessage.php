<?php

namespace App\Models;

use App\Core\Model;

class ChatMessage extends Model
{
    protected static string $table = 'chat_messages';

    public static function getForRequest(int $requestId): array
    {
        $sql = "SELECT m.*, u.name as sender_name FROM chat_messages m JOIN users u ON u.id = m.sender_id WHERE m.request_id = :req_id ORDER BY m.created_at ASC";
        return self::query($sql, ['req_id' => $requestId]);
    }
}
