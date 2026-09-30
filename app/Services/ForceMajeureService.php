<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Models\WaitlistEntry;
use App\Support\ThaiDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * รอบที่ออกไม่ได้เพราะเหตุสุดวิสัย (น้ำป่า พายุ อุทยานสั่งปิด) — เงื่อนไขการจองข้อ 6
 *
 * แทนที่จะคืนเงิน ทีมงานกดปุ่มเดียว รอบถูกยกเลิก และทุกใบจองในรอบได้ "สิทธิ์เลือก
 * รอบใหม่ของทริปเดิม" ที่ออกเดินทางได้ภายใน force_majeure_postpone_months เดือน
 * นับจากวันเดินทางเดิม ราคาเดิม ไม่มีค่าธรรมเนียม และไม่กินสิทธิ์เลื่อนปกติ
 *
 * ใบจองยัง confirmed/pending อยู่บนรอบที่ยกเลิก (เงินยังอยู่กับเรา) จนกว่าลูกค้าจะ
 * เลือกเองผ่าน BookingService::rescheduleBooking() หรือทีมงานย้ายให้ ระหว่างนั้น
 * งานตามรอบทั้งหลายเงียบเองเพราะกรองรอบ status = cancelled ออกอยู่แล้ว ส่วนการทวง
 * ยอดค้างชำระหยุดด้วย Booking::scopeNotAwaitingNewRound()
 */
class ForceMajeureService
{
    /** เตือนลูกค้าที่ยังไม่เลือกรอบ เมื่อเหลือเวลาเท่านี้วัน */
    public const REMIND_DAYS_LEFT = [30, 7, 1];

    public const TIMEZONE = 'Asia/Bangkok';

    public function __construct(
        private MailService $mail,
        private SmsService $sms,
    ) {}

    /**
     * ลิงก์เลือกรอบใหม่บนเว็บ — ใช้ในอีเมล/SMS ซึ่งอาจถูกเปิดบนเครื่องที่ไม่มีแอป
     * หน้า "การจองของฉัน" เปิดหน้าต่างเลือกรอบให้เองเมื่อเห็น ?reschedule=
     */
    public static function chooseUrl(Booking $booking): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/')
            .'/my-bookings?reschedule='.rawurlencode($booking->booking_ref);
    }

    public static function postponeMonths(): int
    {
        return max(1, (int) config('legal.policy.force_majeure_postpone_months', 6));
    }

    /**
     * วันสุดท้ายที่รอบใหม่ออกเดินทางได้ — นับจากวันเดินทางเดิม (วันไทยล้วน ๆ)
     */
    public static function windowEndFor(TripSchedule $schedule): Carbon
    {
        return Carbon::parse($schedule->departure_date->toDateString(), self::TIMEZONE)
            ->addMonthsNoOverflow(self::postponeMonths())
            ->startOfDay();
    }

    /**
     * ยกเลิกรอบเพราะเหตุสุดวิสัย และมอบสิทธิ์เลือกรอบใหม่ให้ทุกใบจองในรอบ
     *
     * ใช้ย้อนหลังกับรอบที่ผ่านไปแล้วได้ (ทีมงานมักยกเลิกหน้างาน) และกับรอบที่
     * ถูกตั้งเป็น "ยกเลิก" ไว้ก่อนหน้าด้วยมือ
     *
     * @return array{bookings: int, until: string}
     */
    public function postponeSchedule(TripSchedule $schedule, string $reason): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \Exception('กรุณาระบุเหตุผล เช่น น้ำป่า อุทยานประกาศปิด');
        }

        [$schedule, $bookings, $waitlist] = DB::transaction(function () use ($schedule, $reason) {
            $schedule = TripSchedule::with('trip')->lockForUpdate()->findOrFail($schedule->id);

            if ($schedule->force_majeure_at !== null) {
                throw new \Exception('รอบนี้ถูกเลื่อนเพราะเหตุสุดวิสัยไปแล้ว');
            }

            if (! $schedule->departure_date) {
                throw new \Exception('รอบนี้ยังไม่มีวันเดินทาง');
            }

            $until = self::windowEndFor($schedule)->toDateString();

            $schedule->forceFill([
                'status' => 'cancelled',
                'force_majeure_at' => now(),
                'force_majeure_reason' => $reason,
            ])->save();

            $bookings = $schedule->bookings()
                ->whereIn('status', Booking::MODIFIABLE_STATUSES)
                ->lockForUpdate()
                ->get();

            foreach ($bookings as $booking) {
                $booking->forceFill([
                    'force_majeure_at' => now(),
                    'force_majeure_reason' => $reason,
                    'force_majeure_until' => $until,
                    'force_majeure_schedule_id' => $schedule->id,
                    'force_majeure_resolved_at' => null,
                ])->save();
            }

            // คิวรอที่นั่งของรอบที่ไม่ออกแล้ว — ไม่มีอะไรให้รออีก
            $waitlist = WaitlistEntry::where('schedule_id', $schedule->id)
                ->whereIn('status', ['waiting', 'offered'])
                ->get();
            WaitlistEntry::whereKey($waitlist->modelKeys())->update(['status' => 'cancelled']);

            return [$schedule, $bookings, $waitlist];
        });

        foreach ($bookings as $booking) {
            $this->notifyPostponed($booking->fresh(['user', 'passengers', 'schedule.trip']));
        }

        $this->notifyWaitlist($schedule, $waitlist);
        $this->notifyStaff($schedule, $bookings->count());

        return [
            'bookings' => $bookings->count(),
            'until' => self::windowEndFor($schedule)->toDateString(),
        ];
    }

    /**
     * ทีมงานย้ายใบที่รอเลือกรอบไปรอบใหม่ให้เอง (หน้าย้ายผู้โดยสาร) — ปิดเรื่องแบบ
     * เดียวกับที่ลูกค้าเลือกเอง
     */
    public function markResolved(Booking $booking): void
    {
        if ($booking->force_majeure_at === null || $booking->force_majeure_resolved_at !== null) {
            return;
        }

        $booking->forceFill(['force_majeure_resolved_at' => now()])->save();
    }

    /**
     * เปิดรอบใหม่ของทริปที่มีคนรอเลือกรอบอยู่ — บอกเขาทันที ไม่ต้องรอให้เข้ามาเช็คเอง
     * (เรียกจาก TripScheduleObserver ตอนรอบเปิดรับจอง)
     */
    public function announceNewRound(TripSchedule $schedule): int
    {
        if ($schedule->status !== 'open' || ! $schedule->departure_date) {
            return 0;
        }

        $date = $schedule->departure_date->toDateString();

        if ($date < now(self::TIMEZONE)->toDateString()) {
            return 0;
        }

        $schedule->loadMissing('trip');
        $bookings = Booking::query()
            ->awaitingNewRound()
            ->whereIn('status', Booking::MODIFIABLE_STATUSES)
            ->whereNotNull('user_id')
            ->whereDate('force_majeure_until', '>=', $date)
            ->whereHas('forceMajeureSchedule', fn ($q) => $q->where('trip_id', $schedule->trip_id))
            ->withCount('passengers')
            ->get();

        $sent = 0;

        foreach ($bookings as $booking) {
            // รอบที่ที่นั่งไม่พอสำหรับทั้งกลุ่ม ชวนไปก็กดเลือกไม่ได้
            if (! $booking->is_join_trip && $schedule->available_seats < $booking->passengers_count) {
                continue;
            }

            $alreadySent = SmartNotification::where('user_id', $booking->user_id)
                ->where('type', 'trip_postponed_new_round')
                ->where('data->booking_ref', $booking->booking_ref)
                ->where('data->schedule_id', $schedule->id)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            SmartNotification::send(
                $booking->user_id,
                'trip_postponed_new_round',
                'เปิดรอบใหม่แล้ว เลือกได้เลย',
                'ทริป '.($schedule->trip?->title ?? '').' เปิดรอบ '.ThaiDate::short($schedule->departure_date)
                    .' แล้ว ใช้สิทธิ์เลือกรอบใหม่ของการจอง '.$booking->booking_ref.' ได้ ราคาเดิม',
                [
                    'booking_ref' => $booking->booking_ref,
                    'schedule_id' => $schedule->id,
                    'route' => 'booking',
                ],
            );
            $sent++;
        }

        return $sent;
    }

    /**
     * เตือนคนที่ยังไม่ได้เลือกรอบ เมื่อเหลือ 30 / 7 / 1 วัน (งานรายวัน)
     *
     * @return array{reminded: int}
     */
    public function sendDeadlineReminders(): array
    {
        $today = Carbon::now(self::TIMEZONE)->startOfDay();
        $reminded = 0;

        foreach (self::REMIND_DAYS_LEFT as $daysLeft) {
            $target = $today->copy()->addDays($daysLeft)->toDateString();

            $bookings = Booking::query()
                ->awaitingNewRound()
                ->whereIn('status', Booking::MODIFIABLE_STATUSES)
                ->whereNotNull('user_id')
                ->whereDate('force_majeure_until', $target)
                ->with(['user', 'passengers', 'schedule.trip'])
                ->get();

            foreach ($bookings as $booking) {
                $alreadySent = SmartNotification::where('user_id', $booking->user_id)
                    ->where('type', 'trip_postponed_reminder')
                    ->where('data->booking_ref', $booking->booking_ref)
                    ->where('data->days_left', $daysLeft)
                    ->exists();

                if ($alreadySent) {
                    continue;
                }

                $until = ThaiDate::full($booking->force_majeure_until);

                SmartNotification::send(
                    $booking->user_id,
                    'trip_postponed_reminder',
                    $daysLeft === 1 ? 'พรุ่งนี้หมดสิทธิ์เลือกรอบใหม่' : "อีก {$daysLeft} วันหมดสิทธิ์เลือกรอบใหม่",
                    'การจอง '.$booking->booking_ref.' ทริป '.($booking->schedule?->trip?->title ?? '')
                        .' ยังไม่ได้เลือกรอบใหม่ เลือกรอบที่ออกเดินทางภายใน '.$until.' ได้ ราคาเดิม',
                    [
                        'booking_ref' => $booking->booking_ref,
                        'days_left' => $daysLeft,
                        'route' => 'booking',
                    ],
                );

                // SMS เฉพาะรอบ 7 วัน — คนที่ไม่มีแอปและไม่ได้ผูก LINE จะได้รู้ก่อนสาย
                if ($daysLeft === 7) {
                    $this->sms->sendTripPostponedReminder($booking);
                }

                $reminded++;
            }
        }

        return ['reminded' => $reminded];
    }

    /**
     * สรุปสำหรับทีมงาน — ใครเลือกรอบแล้ว ใครยังไม่เลือก ใครหมดสิทธิ์
     *
     * @return array<string, mixed>
     */
    public function overview(TripSchedule $schedule): array
    {
        $bookings = Booking::query()
            ->where('force_majeure_schedule_id', $schedule->id)
            ->with(['user:id,name,phone,email', 'schedule:id,departure_date,departs_at,status'])
            ->withCount('passengers')
            ->orderBy('id')
            ->get();

        $rows = $bookings->map(fn (Booking $booking) => $this->overviewRow($booking))->values();

        return [
            'schedule_id' => $schedule->id,
            'force_majeure_at' => $schedule->force_majeure_at?->toISOString(),
            'reason' => $schedule->force_majeure_reason,
            'until' => $schedule->departure_date && $schedule->force_majeure_at
                ? self::windowEndFor($schedule)->toDateString()
                : null,
            'counts' => [
                'total' => $rows->count(),
                'awaiting' => $rows->where('state', 'awaiting')->count(),
                'moved' => $rows->where('state', 'moved')->count(),
                'expired' => $rows->where('state', 'expired')->count(),
                'cancelled' => $rows->where('state', 'cancelled')->count(),
            ],
            'bookings' => $rows,
        ];
    }

    /**
     * ข้อมูลของสิทธิ์นี้สำหรับหน้าจอลูกค้า — null เมื่อใบนี้ไม่เคยโดนเลื่อน
     *
     * @return array<string, mixed>|null
     */
    public static function customerPayload(Booking $booking): ?array
    {
        if ($booking->force_majeure_at === null) {
            return null;
        }

        $awaiting = $booking->awaitsNewRound();
        $until = $booking->force_majeure_until;
        $daysLeft = $until
            ? (int) Carbon::now(self::TIMEZONE)->startOfDay()
                ->diffInDays(Carbon::parse($until->toDateString(), self::TIMEZONE), false)
            : null;
        $original = $booking->relationLoaded('forceMajeureSchedule')
            ? $booking->forceMajeureSchedule
            : $booking->forceMajeureSchedule()->first();

        return [
            'reason' => $booking->force_majeure_reason,
            'original_departure_date' => $original?->departure_date?->toDateString(),
            'original_departure_label' => $original ? ThaiDate::full($original->departure_date) : null,
            // เลือกรอบที่ออกเดินทางได้ไม่เกินวันนี้
            'until' => $until?->toDateString(),
            'until_label' => $until ? ThaiDate::full($until) : null,
            'awaiting' => $awaiting,
            'can_choose' => $booking->canChooseForceMajeureRound(),
            'expired' => $awaiting && ! $booking->canChooseForceMajeureRound(),
            'days_left' => $awaiting ? $daysLeft : null,
            'resolved_at' => $booking->force_majeure_resolved_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function overviewRow(Booking $booking): array
    {
        $state = match (true) {
            ! in_array($booking->status, Booking::MODIFIABLE_STATUSES, true) => 'cancelled',
            $booking->force_majeure_resolved_at !== null => 'moved',
            $booking->canChooseForceMajeureRound() => 'awaiting',
            default => 'expired',
        };

        return [
            'booking_ref' => $booking->booking_ref,
            'status' => $booking->status,
            'state' => $state,
            'customer_name' => $booking->user?->name,
            'customer_phone' => $booking->user?->phone,
            'passengers_count' => (int) $booking->passengers_count,
            'total_amount' => (float) $booking->total_amount,
            'paid_amount' => (float) $booking->paid_amount,
            'until' => $booking->force_majeure_until?->toDateString(),
            'resolved_at' => $booking->force_majeure_resolved_at?->toISOString(),
            'moved_to' => $state === 'moved' && $booking->schedule
                ? [
                    'schedule_id' => $booking->schedule->id,
                    'departure_date' => $booking->schedule->departure_date?->toDateString(),
                    'label' => ThaiDate::full($booking->schedule->departure_date),
                ]
                : null,
        ];
    }

    private function notifyPostponed(Booking $booking): void
    {
        $schedule = $booking->schedule;
        $tripTitle = $schedule?->trip?->title ?? 'ทริป';
        $until = ThaiDate::full($booking->force_majeure_until);

        if ($booking->user_id) {
            try {
                SmartNotification::send(
                    $booking->user_id,
                    'trip_postponed',
                    'รอบเดินทางต้องเลื่อน · เลือกรอบใหม่ได้เลย',
                    $tripTitle.' วันที่ '.ThaiDate::short($schedule?->departure_date)
                        .' ออกเดินทางไม่ได้ เนื่องจาก'.$booking->force_majeure_reason
                        .' ยอดที่ชำระไว้ยังอยู่ครบ เลือกรอบใหม่ได้ฟรี ราคาเดิม ภายใน '.$until,
                    [
                        'booking_ref' => $booking->booking_ref,
                        'schedule_id' => $schedule?->id,
                        'route' => 'booking',
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('Force majeure push failed', [
                    'booking_ref' => $booking->booking_ref,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->mail->sendTripPostponedEmail($booking);
        $this->sms->sendTripPostponed($booking);
    }

    private function notifyWaitlist(TripSchedule $schedule, Collection $entries): void
    {
        foreach ($entries as $entry) {
            try {
                SmartNotification::send(
                    $entry->user_id,
                    'waitlist_round_cancelled',
                    'รอบที่คุณรอคิวถูกยกเลิก',
                    ($schedule->trip?->title ?? 'ทริป').' รอบ '.ThaiDate::short($schedule->departure_date)
                        .' ยกเลิกเนื่องจาก'.$schedule->force_majeure_reason.' ลองดูรอบอื่นของทริปนี้ได้เลย',
                    [
                        'trip_slug' => $schedule->trip?->slug,
                        'schedule_id' => $schedule->id,
                        'route' => 'trip',
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('Force majeure waitlist push failed', ['message' => $e->getMessage()]);
            }
        }
    }

    private function notifyStaff(TripSchedule $schedule, int $bookingCount): void
    {
        foreach ($schedule->activeStaff()->get() as $staff) {
            try {
                SmartNotification::send(
                    $staff->id,
                    'schedule_cancelled',
                    'รอบเดินทางถูกยกเลิก',
                    ($schedule->trip?->title ?? 'ทริป').' รอบ '.ThaiDate::short($schedule->departure_date)
                        .' ยกเลิกเนื่องจาก'.$schedule->force_majeure_reason
                        ." ลูกค้า {$bookingCount} รายการได้รับแจ้งให้เลือกรอบใหม่แล้ว",
                    ['schedule_id' => $schedule->id],
                );
            } catch (\Throwable $e) {
                Log::warning('Force majeure staff push failed', ['message' => $e->getMessage()]);
            }
        }
    }
}
