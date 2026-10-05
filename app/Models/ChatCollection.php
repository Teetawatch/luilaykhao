<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * เก็บเงินหน้างาน (ค่าใช้จ่ายนอกแพ็กเกจ) — ผูกกับการ์ดหนึ่งใบในห้องแชท
 */
class ChatCollection extends Model
{
    public const MAX_AMOUNT = 100000;

    protected $fillable = [
        'schedule_id', 'message_id', 'created_by_id', 'title', 'note', 'amount',
        'promptpay_id', 'payee_name', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function dues(): HasMany
    {
        return $this->hasMany(ChatCollectionDue::class, 'collection_id')->orderBy('id');
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }
}
