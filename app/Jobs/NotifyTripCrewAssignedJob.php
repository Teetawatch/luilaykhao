<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * แจ้ง "ลูกค้า" ทันทีที่ข้อมูลรถ/คนขับหรือสตาฟของรอบพร้อมแล้ว
 *
 * ก่อนหน้านี้เรามีแต่ push หาคนขับ (SendDriverAssignmentPushJob) และสตาฟ
 * (SendStaffAssignmentPushJob) ส่วนลูกค้าไม่เคยรู้ว่าข้อมูลมาแล้ว — ต้องเปิดแอป
 * เช็กเองหรือไม่ก็ทักมาถาม ซึ่งเป็นคำถามยอดฮิตอันดับหนึ่งในห้องแชท
 *
 * กันยิงซ้ำด้วยการเทียบ "เนื้อความเดิม" ของรอบเดียวกัน — แอดมินเพิ่มสตาฟทีละคน
 * หรือกดบันทึกซ้ำ ลูกค้าจะไม่โดนเด้งรัว ๆ แต่ถ้าเปลี่ยนรถ/ทะเบียนจริง เนื้อความ
 * เปลี่ยน ก็จะแจ้งใหม่ตามที่ควรเป็น รอบที่ยกเลิกหรือเดินทางไปแล้วไม่ส่ง
 */
class NotifyTripCrewAssignedJob implements ShouldQueue
{
    use Queueable;

    public const KIND_VEHICLE = 'vehicle';

    public const KIND_STAFF = 'staff';

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(
        public readonly int $scheduleId,
        public readonly string $kind = self::KIND_VEHICLE,
    ) {}

    public function handle(): void
    {
        $schedule = TripSchedule::with(['trip', 'vehicle'])->find($this->scheduleId);

        if (! $schedule || $schedule->status === 'cancelled') {
            return;
        }

        // ผ่านวันเดินทางไปแล้วไม่ต้องแจ้ง (เช่น แอดมินแก้ข้อมูลรถย้อนหลัง)
        $departsAt = $schedule->effectiveDepartsAt();
        if ($departsAt && $departsAt->isPast()) {
            return;
        }

        [$title, $body] = $this->message($schedule);
        if ($body === null) {
            return;
        }

        $bookings = Booking::where('schedule_id', $schedule->id)
            ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
            ->get();

        $type = "trip_crew_{$this->kind}";

        foreach ($bookings as $booking) {
            foreach ($booking->accessUserIds() as $userId) {
                if ($this->alreadySent($userId, $type, $schedule->id, $body)) {
                    continue;
                }

                SmartNotification::send(
                    $userId,
                    $type,
                    $title,
                    $body,
                    [
                        'booking_ref' => $booking->booking_ref,
                        'trip_id' => $schedule->trip_id,
                        'schedule_id' => $schedule->id,
                        'route' => 'booking',
                    ],
                );
            }
        }
    }

    /**
     * เคยแจ้งข้อความเดียวกันของรอบนี้ให้คนนี้ไปแล้วหรือยัง
     */
    private function alreadySent(int $userId, string $type, int $scheduleId, string $body): bool
    {
        return SmartNotification::where('user_id', $userId)
            ->where('type', $type)
            ->where('body', $body)
            ->where('data->schedule_id', $scheduleId)
            ->exists();
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function message(TripSchedule $schedule): array
    {
        $tripTitle = $schedule->trip?->title ?? 'ทริปของคุณ';

        if ($this->kind === self::KIND_STAFF) {
            $names = $schedule->activeStaff()
                ->pluck('users.nickname')
                ->filter()
                ->take(2)
                ->implode(' และ ');

            $who = $names !== '' ? "สตาฟประจำรอบคือ {$names} " : '';

            return [
                'ทีมงานประจำรอบพร้อมแล้ว 🎽',
                "{$tripTitle} — {$who}ดูชื่อและเบอร์ติดต่อได้ที่ใบจองในแอปแล้วครับ",
            ];
        }

        $vehicle = $schedule->vehicle;
        if (! $vehicle) {
            return ['', null];
        }

        $plate = trim((string) $vehicle->license_plate);
        $driver = trim((string) $vehicle->driver_name);

        $detail = collect([
            $plate !== '' ? "ทะเบียน {$plate}" : null,
            $driver !== '' ? "คนขับ {$driver}" : null,
        ])->filter()->implode(' · ');

        // ยังไม่มีทั้งทะเบียนและชื่อคนขับ = ยังไม่มีอะไรให้แจ้ง
        if ($detail === '') {
            return ['', null];
        }

        return [
            'รถและคนขับของคุณพร้อมแล้ว 🚐',
            "{$tripTitle} — {$detail} ดูรายละเอียดและกดโทรหาคนขับได้ที่ใบจองในแอปครับ",
        ];
    }
}
