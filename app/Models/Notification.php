<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    // Notification center mobile memakai tabel sendiri di DB sagansa agar
    // tidak bentrok dengan tabel `notifications` standar Laravel yang dipakai
    // Filament admin (notifiable_type/notifiable_id).
    protected $connection = 'mysql';
    protected $table = 'notification_center';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }
}
