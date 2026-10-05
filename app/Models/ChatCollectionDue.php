<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatCollectionDue extends Model
{
    protected $fillable = [
        'collection_id', 'passenger_id', 'amount', 'paid_claimed_at', 'paid_at', 'paid_marked_by_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_claimed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(ChatCollection::class, 'collection_id');
    }

    public function status(): string
    {
        return match (true) {
            $this->paid_at !== null => 'paid',
            $this->paid_claimed_at !== null => 'claimed',
            default => 'unpaid',
        };
    }
}
