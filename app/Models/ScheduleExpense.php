<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScheduleExpense extends Model
{
    // ลบรายการเงินแล้วต้องยังตามรอยได้ — ซ่อนจากยอดรวม แต่ปูมยังชี้กลับมาถึง
    use SoftDeletes;

    public const KIND_EXPENSE = 'expense';

    public const KIND_INCOME = 'income';

    public const KINDS = [self::KIND_EXPENSE, self::KIND_INCOME];

    /**
     * หมวดที่สตาฟเลือกได้หน้างาน — key เก็บลง DB, ค่าเป็นป้ายภาษาไทยที่แอปโชว์
     * (แอดมินคีย์รายการเองไม่ต้องมีหมวดก็ได้ จึง nullable)
     */
    public const EXPENSE_CATEGORIES = [
        'food' => 'อาหาร/เครื่องดื่ม',
        'fuel' => 'น้ำมัน/ทางด่วน',
        'transport' => 'ค่ารถ/เรือ',
        'accommodation' => 'ที่พัก',
        'ticket' => 'ค่าเข้า/อุทยาน',
        'equipment' => 'อุปกรณ์',
        'staff' => 'ค่าจ้างทีมงาน/ไกด์',
        'other' => 'อื่นๆ',
    ];

    public const INCOME_CATEGORIES = [
        'onsite_payment' => 'เก็บเงินหน้างาน',
        'rental' => 'ค่าเช่าอุปกรณ์',
        'extra' => 'ค่าใช้จ่ายเพิ่มเติม',
        'refund_back' => 'เงินคืนจากร้าน',
        'other' => 'อื่นๆ',
    ];

    protected $fillable = [
        'schedule_id', 'expense_template_id', 'kind', 'category', 'name',
        'amount', 'note', 'slip_path', 'spent_at', 'created_by', 'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'spent_at' => 'date',
        ];
    }

    public function scopeExpenses(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_EXPENSE);
    }

    public function scopeIncome(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_INCOME);
    }

    public function isIncome(): bool
    {
        return $this->kind === self::KIND_INCOME;
    }

    /** ป้ายหมวดภาษาไทย — คืน null เมื่อไม่ได้ระบุหมวด (รายการที่แอดมินคีย์เอง) */
    public function categoryLabel(): ?string
    {
        if (! $this->category) {
            return null;
        }

        $map = $this->isIncome() ? self::INCOME_CATEGORIES : self::EXPENSE_CATEGORIES;

        return $map[$this->category] ?? null;
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ExpenseTemplate::class, 'expense_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(ScheduleExpenseAudit::class, 'expense_id');
    }

    /** ฟิลด์ที่มีความหมายทางบัญชี — ใช้เป็น snapshot ก่อน/หลังในปูม */
    public function auditSnapshot(): array
    {
        return [
            'kind' => $this->kind,
            'category' => $this->category,
            'name' => $this->name,
            'amount' => (float) $this->amount,
            'note' => $this->note,
            'spent_at' => $this->spent_at?->toDateString(),
            'has_slip' => (bool) $this->slip_path,
        ];
    }
}
