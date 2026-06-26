<?php

namespace App\Jobs;

use App\Models\SmartNotification;
use App\Models\TripSchedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * เตือนสตาฟที่ถูกมอบหมายงาน ในเย็นก่อนวันเดินทาง 1 วัน (in-app + FCM push)
 * ให้เตรียมอุปกรณ์/ความพร้อม รันทุกวันเวลา 18:00 (Asia/Bangkok) จาก console.php
 *
 * นับวันจากเวลาออกเดินทางจริง (departs_at ผ่าน scope departingOn) เพื่อให้รอบที่
 * รถออกคืนก่อนวันทริปได้รับการเตือนถูกวัน — เช่นเดียวกับ SendTripReminderNotificationsJob
 */
class SendStaffShiftRemindersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    private const DAYS_BEFORE = 1;

    public function handle(): void
    {
        $sent = 0;

        $schedules = TripSchedule::query()
            ->departingOn(now()->addDays(self::DAYS_BEFORE))
            ->where('status', '!=', 'cancelled')
            ->with(['trip', 'vehicle', 'staff'])
            ->get();

        foreach ($schedules as $schedule) {
            $tripTitle = $schedule->trip?->title ?? 'ทริปของคุณ';

            $departsAt = $schedule->departs_at;
            $timeNote = $departsAt ? ' เวลา '.$departsAt->format('H:i').' น.' : '';
            $body = "{$tripTitle} ออกเดินทางพรุ่งนี้{$timeNote} เตรียมอุปกรณ์และความพร้อมให้เรียบร้อย";

            foreach ($schedule->staff as $staff) {
                if ($this->alreadySent($staff->id, $schedule->id)) {
                    continue;
                }

                SmartNotification::send(
                    $staff->id,
                    'staff_shift_reminder',
                    '🎒 พรุ่งนี้มีงานนำทริป',
                    $body,
                    [
                        'type' => 'staff_shift_reminder',
                        'route' => 'staff_shift_reminder',
                        'schedule_id' => (string) $schedule->id,
                        'trip_id' => (string) ($schedule->trip_id ?? ''),
                        'days_before' => self::DAYS_BEFORE,
                    ],
                );

                $sent++;
            }
        }

        Log::info('SendStaffShiftRemindersJob completed', ['sent' => $sent]);
    }

    /**
     * กันการส่งซ้ำหาก job รันมากกว่าหนึ่งครั้งในวันเดียวกัน.
     */
    private function alreadySent(int $staffId, int $scheduleId): bool
    {
        return SmartNotification::where('user_id', $staffId)
            ->where('type', 'staff_shift_reminder')
            ->where('data->schedule_id', (string) $scheduleId)
            ->where('data->days_before', self::DAYS_BEFORE)
            ->exists();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendStaffShiftRemindersJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
