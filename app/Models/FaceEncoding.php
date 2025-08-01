<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class FaceEncoding extends Model
{
    protected $fillable = [
        'user_id',
        'encoding',
        'encoding_version',
        'is_active',
        'registered_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'registered_at' => 'datetime',
    ];

    /**
     * Get the user that owns the face encoding.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Set the encoding attribute with encryption.
     */
    public function setEncodingAttribute($value): void
    {
        $this->attributes['encoding'] = Crypt::encryptString(json_encode($value));
    }

    /**
     * Get the encoding attribute with decryption.
     */
    public function getEncodingAttribute($value): array
    {
        if (empty($value)) {
            return [];
        }
        
        try {
            return json_decode(Crypt::decryptString($value), true) ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get the active face encoding for a user.
     */
    public static function getActiveEncodingForUser(int $userId): ?self
    {
        return static::where('user_id', $userId)
            ->where('is_active', true)
            ->latest('registered_at')
            ->first();
    }

    /**
     * Deactivate all face encodings for a user.
     */
    public static function deactivateUserEncodings(int $userId): void
    {
        static::where('user_id', $userId)->update(['is_active' => false]);
    }
}
