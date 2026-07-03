<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DeviceToken extends Model
{
    use HasFactory;

    // device_tokens berada di DB yang sama dengan users (mysql_auth/sagansa_user).
    protected $connection = 'mysql_auth';

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
