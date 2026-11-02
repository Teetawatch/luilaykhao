<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseTemplate extends Model
{
    /** ยอดเหมาต่อรอบ — default_amount คือยอดทั้งก้อน */
    public const BASIS_FLAT = 'flat';

    /** ต่อลูกทริปที่ไปจริง — default_amount × จำนวนลูกทริป */
    public const BASIS_PER_TRAVELLER = 'per_traveller';

    /** ต่อหัวทุกคนในรถ — ลูกทริป + ทีมงาน (เช่นค่าผ่านด่านที่ทีมงานก็ต้องจ่าย) */
    public const BASIS_PER_PERSON = 'per_person';

    public const BASES = [
        self::BASIS_FLAT => 'เหมาต่อรอบ',
        self::BASIS_PER_TRAVELLER => 'ต่อลูกทริป',
        self::BASIS_PER_PERSON => 'ต่อคน (รวมทีมงาน)',
    ];

    protected $fillable = [
        'trip_id', 'name', 'category', 'default_amount', 'amount_basis',
        'staff_visible', 'staff_note', 'sort_order', 'is_active',
    ];

    protected $attributes = [
        'amount_basis' => self::BASIS_FLAT,
        'staff_visible' => false,
    ];

    protected function casts(): array
    {
        return [
            'default_amount' => 'decimal:2',
            'staff_visible' => 'boolean',
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

    public function isPerHead(): bool
    {
        return $this->amount_basis !== self::BASIS_FLAT;
    }

    /** จำนวนคนที่ใช้คูณ — null สำหรับรายการเหมา */
    public function headsFor(int $travellers, int $staff): ?int
    {
        return match ($this->amount_basis) {
            self::BASIS_PER_TRAVELLER => $travellers,
            self::BASIS_PER_PERSON => $travellers + $staff,
            default => null,
        };
    }

    /** ยอดของรอบ — null เมื่อแอดมินไม่ได้ตั้งยอดไว้ (ให้กรอกตามจริงตอนจ่าย) */
    public function amountFor(int $travellers, int $staff): ?float
    {
        if ($this->default_amount === null) {
            return null;
        }

        $heads = $this->headsFor($travellers, $staff);

        return round((float) $this->default_amount * ($heads ?? 1), 2);
    }
}
