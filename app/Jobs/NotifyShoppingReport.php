<?php

namespace App\Jobs;

use App\Models\ScheduleShoppingItem;
use App\Models\ScheduleShoppingReport;
use App\Models\SmartNotification;
use App\Models\User;
use App\Support\ThaiDate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * แจ้งเตือนเรื่องรายงานซื้อของของรอบ
 *
 * - submitted: สตาฟส่งรายงานแล้ว → แอดมิน/operator ทุกคน
 * - reopened:  แอดมินตีกลับให้แก้ → สตาฟคนที่ส่ง
 */
class NotifyShoppingReport implements ShouldQueue
{
    use Queueable;

    public const SUBMITTED = 'submitted';

    public const REOPENED = 'reopened';

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(private int $reportId, private string $event) {}

    public function handle(): void
    {
        $report = ScheduleShoppingReport::with(['schedule.trip', 'submittedBy'])->find($this->reportId);
        $schedule = $report?->schedule;

        if (! $report || ! $schedule) {
            return;
        }

        $round = ($schedule->trip?->title ?? 'ทริป').' '.ThaiDate::short($schedule->departure_date);
        $data = ['schedule_id' => (string) $schedule->id, 'report_id' => (string) $report->id];

        if ($this->event === self::REOPENED) {
            if ($report->submitted_by_id) {
                SmartNotification::send(
                    (int) $report->submitted_by_id,
                    'shopping_report_reopened',
                    'แอดมินขอให้แก้รายงานซื้อของ',
                    $round.' — '.Str::limit((string) $report->reopen_reason, 120),
                    $data,
                );
            }

            return;
        }

        $items = ScheduleShoppingItem::where('schedule_id', $schedule->id);
        $total = (clone $items)->count();
        $bought = (clone $items)->whereNotNull('bought_at')->count();

        $parts = [($report->submittedBy?->name ?? 'สตาฟ').' ซื้อของรอบ '.$round];
        if ($total > 0) {
            $parts[] = $bought === $total ? "ครบ {$total} รายการ" : "ได้ {$bought}/{$total} รายการ";
        }
        if ($report->total_amount) {
            $parts[] = '฿'.number_format($report->total_amount, 0);
        }

        $opsIds = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'operator']))
            ->pluck('id')
            ->reject(fn ($id) => (int) $id === (int) $report->submitted_by_id);

        foreach ($opsIds as $id) {
            SmartNotification::send(
                (int) $id,
                'shopping_report',
                '🛒 ส่งรายงานซื้อของแล้ว',
                implode(' · ', $parts),
                $data,
            );
        }
    }
}
