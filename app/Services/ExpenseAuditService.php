<?php

namespace App\Services;

use App\Models\ScheduleExpense;
use App\Models\ScheduleExpenseAudit;
use App\Models\TripSchedule;
use App\Models\User;

/**
 * ปูมบัญชี — บันทึกทุกครั้งที่ตัวเลขเงินของรอบเดินทางขยับ
 *
 * แยกจาก [ScheduleFinanceService] เพื่อให้ทั้งฝั่งแอดมิน (คีย์บนเว็บ) และฝั่ง
 * สตาฟ (จดหน้างานผ่านแอป) เขียนปูมชุดเดียวกันได้โดยไม่ต้องพึ่งพากันเอง
 */
class ExpenseAuditService
{
    public function created(ScheduleExpense $expense, ?User $actor, ?string $reason = null): void
    {
        $this->write($expense->schedule_id, $expense->id, ScheduleExpenseAudit::ACTION_CREATED, null, $expense->auditSnapshot(), $actor, $reason);
    }

    public function updated(ScheduleExpense $expense, array $before, ?User $actor, ?string $reason = null): void
    {
        $after = $expense->auditSnapshot();

        // ไม่มีอะไรเปลี่ยนก็ไม่ต้องมีแถวในปูม — กันปูมบวมจากการกดบันทึกซ้ำ
        if ($before === $after) {
            return;
        }

        $this->write($expense->schedule_id, $expense->id, ScheduleExpenseAudit::ACTION_UPDATED, $before, $after, $actor, $reason);
    }

    public function deleted(ScheduleExpense $expense, ?User $actor, ?string $reason = null): void
    {
        $this->write($expense->schedule_id, $expense->id, ScheduleExpenseAudit::ACTION_DELETED, $expense->auditSnapshot(), null, $actor, $reason);
    }

    public function closed(TripSchedule $schedule, ?User $actor, array $totals, ?string $reason = null): void
    {
        $this->write($schedule->id, null, ScheduleExpenseAudit::ACTION_CLOSED, null, $totals, $actor, $reason);
    }

    public function reopened(TripSchedule $schedule, ?User $actor, ?string $reason = null): void
    {
        $this->write($schedule->id, null, ScheduleExpenseAudit::ACTION_REOPENED, null, null, $actor, $reason);
    }

    private function write(int $scheduleId, ?int $expenseId, string $action, ?array $before, ?array $after, ?User $actor, ?string $reason): void
    {
        ScheduleExpenseAudit::create([
            'schedule_id' => $scheduleId,
            'expense_id' => $expenseId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $reason ? mb_substr(trim($reason), 0, 500) : null,
            'user_id' => $actor?->id,
        ]);
    }

    /**
     * ปูมของรอบหนึ่ง เรียงใหม่สุดก่อน — พร้อมป้ายภาษาไทยสำหรับหน้าเว็บ
     */
    public function forSchedule(TripSchedule $schedule, int $limit = 200): array
    {
        return ScheduleExpenseAudit::with('user:id,name')
            ->where('schedule_id', $schedule->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ScheduleExpenseAudit $a) => [
                'id' => $a->id,
                'expense_id' => $a->expense_id,
                'action' => $a->action,
                'action_label' => $a->actionLabel(),
                'before' => $a->before,
                'after' => $a->after,
                'reason' => $a->reason,
                'user_name' => $a->user?->name,
                'created_at' => $a->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
