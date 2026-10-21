<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ฝากของลูกหาบ — ผูกกับการ์ดหนึ่งใบในห้องแชท ยอดเงินไปจบที่ [ChatCollection]
 */
class ChatPorterRound extends Model
{
    public const MAX_RATE = 1000;

    public const MAX_WEIGHT_KG = 60;

    /** ลงชื่อฝากได้ทีละไม่เกินกี่ใบต่อคน */
    public const MAX_BAGS_PER_PASSENGER = 5;

    protected $fillable = [
        'schedule_id', 'message_id', 'created_by_id', 'title', 'rate_per_kg', 'min_charge', 'round_up',
        'promptpay_id', 'payee_name', 'collection_id', 'returned_announced_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'rate_per_kg' => 'decimal:2',
            'min_charge' => 'decimal:2',
            'round_up' => 'boolean',
            'returned_announced_at' => 'datetime',
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

    public function bags(): HasMany
    {
        return $this->hasMany(ChatPorterBag::class, 'round_id')->orderBy('tag_no');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(ChatCollection::class, 'collection_id');
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /** ค่าฝากของกระเป๋าใบนี้ — null เมื่อยังไม่ได้ชั่ง */
    public function feeFor(?float $weightKg): ?float
    {
        if ($weightKg === null) {
            return null;
        }

        $kg = $this->round_up ? ceil(round($weightKg, 2)) : $weightKg;
        $fee = round($kg * (float) $this->rate_per_kg, 2);

        return max($fee, (float) $this->min_charge);
    }
}
