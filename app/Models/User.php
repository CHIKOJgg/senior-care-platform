<?php

declare(strict_types=1);

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

    public static function getWithProfile(int $id): ?array
    {
        $user = self::find($id);
        if (!$user) {
            return null;
        }

        $role = Role::find((int)$user['role_id']);
        $user['role_code'] = $role['code'] ?? 'user';
        $user['role_name'] = $role['name'] ?? 'Пользователь';

        $elderly = ElderlyProfile::find($id);
        if ($elderly) {
            $user['profile'] = $elderly;
            $user['profile_type'] = 'elderly';
            return $user;
        }

        $volunteer = VolunteerProfile::find($id);
        if ($volunteer) {
            $user['profile'] = $volunteer;
            $user['profile_type'] = 'volunteer';
            return $user;
        }

        $user['profile'] = null;
        $user['profile_type'] = $user['role_code'];
        return $user;
    }

    public static function getAllByRole(int $roleId): array
    {
        return self::where('role_id', $roleId);
    }
}
