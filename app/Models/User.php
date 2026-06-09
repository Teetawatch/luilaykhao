<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password', 'driver_pin_hash', 'avatar', 'title', 'nickname', 'id_card', 'blood_group', 'emergency_contact', 'emergency_phone', 'allergies', 'health_notes', 'social_provider', 'social_id'])]
#[Hidden(['password', 'driver_pin_hash', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'id_card' => 'encrypted',
            'allergies' => 'encrypted',
            'health_notes' => 'encrypted',
            'marketing_push_enabled' => 'boolean',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function bookingMemberships(): HasMany
    {
        return $this->hasMany(BookingMember::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function loyaltyAccount(): HasOne
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

    public function fcmTokens(): HasMany
    {
        return $this->hasMany(FcmToken::class);
    }

    public function assignedSchedules(): BelongsToMany
    {
        return $this->belongsToMany(TripSchedule::class, 'schedule_staff_assignments', 'user_id', 'schedule_id')
            ->withPivot(['assigned_by', 'created_at'])
            ->withTimestamps();
    }

    public function staffReviewsReceived(): HasMany
    {
        return $this->hasMany(StaffReview::class, 'staff_user_id');
    }

    public function staffReviewsGiven(): HasMany
    {
        return $this->hasMany(StaffReview::class, 'reviewer_user_id');
    }

    public function getAvatarUrlAttribute(): string
    {
        if (! $this->avatar) {
            return 'https://ui-avatars.com/api/?name='.urlencode($this->name).'&background=2D7A4F&color=fff';
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

        return Storage::url($path);
    }
}
