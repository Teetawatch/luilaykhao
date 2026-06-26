<?php

namespace App\Jobs;

use App\Models\SmartNotification;
use App\Models\TripSchedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * แจ้งเตือนสตาฟทันทีที่ถูกมอบหมายเข้ารอบเดินทางใหม่ (in-app + FCM push).
 * รับเฉพาะ id ของสตาฟที่ "เพิ่งถูกเพิ่ม" จาก AdminController::syncScheduleStaff
 * — คนที่อยู่ในรอบอยู่แล้ว/ถูกถอดออก จะไม่ถูกแจ้งซ้ำ
 */
class SendStaffAssignmentPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 15;

    /**
     * @param  array<int>  $staffIds  สตาฟที่เพิ่งถูกมอบหมายเข้ารอบนี้
     */
    public function __construct(
        public readonly int $scheduleId,
        public readonly array $staffIds,
        public readonly ?int $assignedById = null,
    ) {}

    public function handle(): void
    {
        if (empty($this->staffIds)) {
            return;
        }

        $schedule = TripSchedule::with(['trip', 'vehicle'])->find($this->scheduleId);
        if (! $schedule) {
            return;
        }

        $tripTitle = $schedule->trip?->title ?? 'ทริป';
        $location = $schedule->trip?->location;

        $body = "{$tripTitle} · {$schedule->departureLabelThai()}";
        if ($location) {
            $body .= " ({$location})";
        }

        $data = [
            'type' => 'staff_assignment',
            'route' => 'staff_assignment',
            'schedule_id' => (string) $schedule->id,
            'trip_id' => (string) ($schedule->trip_id ?? ''),
            'trip_title' => $tripTitle,
            'departure_date' => $schedule->departure_date?->toDateString() ?? '',
        ];

        foreach (collect($this->staffIds)->unique() as $staffId) {
            $staffId = (int) $staffId;
            if ($this->assignedById !== null && $staffId === $this->assignedById) {
                continue;
            }

            SmartNotification::send(
                $staffId,
                'staff_assignment',
                '📋 ได้รับมอบหมายงานใหม่',
                $body,
                $data,
            );
        }
    }
}
