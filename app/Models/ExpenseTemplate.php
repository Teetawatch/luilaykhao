<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseTemplate extends Model
{
    protected $fillable = [
        'trip_id', 'name', 'default_amount', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_amount' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(ScheduleExpense::class);
    }
}
