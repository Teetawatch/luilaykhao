<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerPayout extends Model
{
    protected $fillable = [
        'partner_id', 'amount', 'commission_count', 'paid_at', 'reference',
        'slip_path', 'note', 'paid_by_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'commission_count' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(PartnerCommission::class, 'payout_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_id');
    }
}
