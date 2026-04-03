<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyReward extends Model
{
    protected $fillable = [
        'name', 'description', 'type', 'points_required',
        'discount_value', 'is_active', 'stock',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'discount_value' => 'decimal:2',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRedemption::class, 'reward_id');
    }
}
