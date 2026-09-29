<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * รายงานปิดท้ายการซื้อของของรอบ — หนึ่งแถวต่อรอบ
 *
 * `submitted_at` null แปลว่าแอดมินตีกลับให้แก้ (รูปเดิมยังอยู่ใน `photos`)
 */
class ScheduleShoppingReport extends Model
{
    protected $fillable = [
        'schedule_id', 'submitted_at', 'submitted_by_id', 'headcount', 'total_amount',
        'note', 'photos', 'expense_id', 'reviewed_at', 'reviewed_by_id',
        'reopened_at', 'reopened_by_id', 'reopen_reason',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'headcount' => 'integer',
            'total_amount' => 'float',
            'photos' => 'array',
        ];
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(ScheduleExpense::class, 'expense_id');
    }
}
