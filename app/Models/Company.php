<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Status constants
    const STATUS_ACTIVE = '1';
    const STATUS_INACTIVE = '2';
    const STATUS_BLACKLIST = '8';

    public static function getStatusText($status)
    {
        return [
            self::STATUS_ACTIVE => 'aktif',
            self::STATUS_INACTIVE => 'tidak aktif',
            self::STATUS_BLACKLIST => 'blacklist',
        ][$status] ?? null;
    }

    // Relationships
    public function users()
    {
        return $this->hasMany(User::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeInactive($query)
    {
        return $query->where('status', self::STATUS_INACTIVE);
    }

    public function scopeBlacklist($query)
    {
        return $query->where('status', self::STATUS_BLACKLIST);
    }

    public function scopeNotBlacklist($query)
    {
        return $query->where('status', '<>', self::STATUS_BLACKLIST);
    }
}
