<?php

namespace App\Models;

use App\Core\Model;

class ElderlyProfile extends Model
{
    protected static string $table = 'elderly_profiles';
    protected static string $primaryKey = 'user_id';
}
