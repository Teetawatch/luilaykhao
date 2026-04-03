<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyAccount extends Model
{
    protected $fillable = ['user_id', 'points', 'lifetime_points', 'tier'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class, 'user_id', 'user_id');
    }

    public function updateTier(): void
    {
        $this->tier = match (true) {
            $this->lifetime_points >= 5000 => 'gold',
            $this->lifetime_points >= 1500 => 'silver',
            default => 'regular',
        };
        $this->save();
    }

    public static function forUser(int $userId): self
    {
        return static::firstOrCreate(['user_id' => $userId], [
            'points' => 0,
            'lifetime_points' => 0,
            'tier' => 'regular',
        ]);
    }
}
