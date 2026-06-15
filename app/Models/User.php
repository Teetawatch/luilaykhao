<?php

namespace App\Models;

use App\Support\MediaDisk;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password', 'driver_pin_hash', 'avatar', 'title', 'nickname', 'id_card', 'birth_date', 'birthdate_token', 'blood_group', 'emergency_contact', 'emergency_phone', 'allergies', 'health_notes', 'social_provider', 'social_id', 'referral_code', 'referred_by'])]
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
            'birth_date' => 'date',
            'allergies' => 'encrypted',
            'health_notes' => 'encrypted',
            'marketing_push_enabled' => 'boolean',
        ];
    }

    /** Age in whole years, computed live from birth_date; null when unknown. */
    public function getAgeAttribute(): ?int
    {
        return $this->birth_date?->age;
    }

    /**
     * Lazily mint a unique token for the public "fill in your birth date" link,
     * reusing the existing one so a customer's link stays stable across sends.
     */
    public function ensureBirthdateToken(): string
    {
        if (! $this->birthdate_token) {
            $this->forceFill(['birthdate_token' => Str::random(16)])->save();
        }

        return $this->birthdate_token;
    }

    public function birthdateUrl(): string
    {
        return url('/birthdate/'.$this->ensureBirthdateToken());
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

    /** Referrals this user created by inviting friends. */
    public function referralsMade(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    /** The user who invited this account, if any. */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
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

        // Legacy avatars written straight into the public web root were saved
        // with a leading slash (/avatars/…); serve those from the app URL.
        if (str_starts_with($this->avatar, '/avatars')) {
            return url(ltrim($this->avatar, '/'));
        }

        // Everything else is a disk-relative path (avatars/… on the media disk).
        return MediaDisk::url($this->avatar) ?? '';
    }
}
