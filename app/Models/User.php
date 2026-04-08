<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password', 'avatar', 'title', 'nickname', 'id_card', 'blood_group', 'emergency_contact', 'emergency_phone', 'allergies', 'health_notes'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'id_card' => 'encrypted',
            'allergies' => 'encrypted',
            'health_notes' => 'encrypted',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function loyaltyAccount(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LoyaltyAccount::class);
    }

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(SmartNotification::class);
    }

    public function getAvatarUrlAttribute(): string
    {
        if (!$this->avatar) {
            return "https://ui-avatars.com/api/?name=" . urlencode($this->name) . "&background=2D7A4F&color=fff";
        }
        
        if (str_starts_with($this->avatar, 'http')) {
            return $this->avatar;
        }

        // Clean leading slashes to prevent double slashes in URL
        $path = ltrim($this->avatar, '/');
        
        // If it starts with avatars, it's stored in public/avatars directamente
        if (str_starts_with($path, 'avatars')) {
            return url($path);
        }

        return \Illuminate\Support\Facades\Storage::url($path);
    }
}
