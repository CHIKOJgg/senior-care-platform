<?php

namespace App\Models;

use App\Core\Model;

class User extends Model
{
    protected static string $table = 'users';

    public static function findByPhone(string $phone): ?array
    {
        $rows = self::where('phone', $phone);
        return $rows[0] ?? null;
    }
}
