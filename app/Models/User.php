<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the face encodings for the user.
     */
    public function faceEncodings()
    {
        return $this->hasMany(FaceEncoding::class);
    }

    /**
     * Get the active face encoding for the user.
     */
    public function activeFaceEncoding()
    {
        return $this->hasOne(FaceEncoding::class)->where('is_active', true)->latest('registered_at');
    }

    /**
     * Check if user has face recognition enabled.
     */
    public function hasFaceRecognition(): bool
    {
        return $this->activeFaceEncoding()->exists();
    }

    // Sanctum tokens relationship is already provided by HasApiTokens trait
}
