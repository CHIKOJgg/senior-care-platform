<?php

namespace App\Models;

use App\Core\Model;

class VolunteerProfile extends Model
{
    protected static string $table = 'volunteer_profiles';
    protected static string $primaryKey = 'user_id';

    public static function getVerified(): array
    {
        return self::query("SELECT vp.*, u.name, u.phone FROM volunteer_profiles vp JOIN users u ON u.id = vp.user_id WHERE vp.is_verified = 1");
    }
}
