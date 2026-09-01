<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ปูมบัญชีของรอบเดินทาง — ทุกครั้งที่ตัวเลขเงินขยับจะมีหนึ่งแถวที่นี่
 *
 * เก็บ before/after เป็น snapshot เฉพาะฟิลด์ที่มีความหมายทางบัญชี ไม่ใช่ทั้งแถว
 * เพราะสิ่งที่ต้องตอบให้ได้คือ "ยอดเปลี่ยนจากเท่าไรเป็นเท่าไร ใครเปลี่ยน"
 */
class ScheduleExpenseAudit extends Model
{
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';

    public const ACTION_CLOSED = 'closed';

    public const ACTION_REOPENED = 'reopened';

    protected $fillable = [
        'schedule_id', 'expense_id', 'action', 'before', 'after', 'reason', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(ScheduleExpense::class, 'expense_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_CREATED => 'เพิ่มรายการ',
            self::ACTION_UPDATED => 'แก้ไขรายการ',
            self::ACTION_DELETED => 'ลบรายการ',
            self::ACTION_CLOSED => 'ปิดงบรอบ',
            self::ACTION_REOPENED => 'เปิดงบกลับ',
            default => $this->action,
        };
    }
}
