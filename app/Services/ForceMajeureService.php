<?php

namespace App\Services;

use App\Jobs\ProcessWaitlistJob;
use App\Models\Booking;
use App\Models\ForceMajeureSeatHold;
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

    /** รอบที่เปิดใหม่: กันที่นั่งให้คนที่ถูกเลื่อนก่อนคนทั่วไปกี่ชั่วโมง */
    public const HOLD_HOURS = 48;

    public function __construct(
        private MailService $mail,
        private SmsService $sms,
        private ChatRoomEventService $chatEvents,
    ) {}

    /**
     * ลิงก์เลือกรอบใหม่ที่ใช้ในอีเมล/SMS — หน้า /reschedule/{token} เลือกได้เลยโดยไม่ต้อง
     * ล็อกอิน เพราะลูกค้าจำนวนมากให้ทีมงานจองให้และอยู่ในบัญชีเงาที่ยังล็อกอินไม่ได้
     * (คนที่มีบัญชีก็ใช้ลิงก์เดียวกันได้ หรือเข้าทางแอป/เว็บ/LINE ตามปกติ)
     */
    public static function chooseUrl(Booking $booking): string
    {
        return $booking->rescheduleUrl();
    }

    /**
     * รอบที่ใบนี้เลือกได้ — ทริปเดิม เปิดรับจอง ยังไม่ผ่านไป ออกเดินทางภายในกรอบสิทธิ์
     * พร้อมที่นั่งที่ใบนี้ใช้ได้จริง (ที่ว่างสาธารณะ + ที่ที่กันไว้ให้ใบนี้เอง)
     * ตัวตัดสินจริงยังเป็น BookingService::rescheduleBooking() — นี่แค่ช่วยให้หน้าจอ
     * ไม่เสนอรอบที่กดแล้วจะโดนปฏิเสธ
     *
     * @return Collection<int, array{schedule: TripSchedule, seats_left: int, fits: bool, hold: ForceMajeureSeatHold|null}>
     */
    public function eligibleRounds(Booking $booking): Collection
    {
        $tripId = $booking->forceMajeureSchedule?->trip_id ?? $booking->schedule?->trip_id;

        if (! $tripId || ! $booking->force_majeure_until) {
            return collect();
        }

        $pax = max(1, $booking->passengers()->count());
        $holds = ForceMajeureSeatHold::active()
            ->where('booking_id', $booking->id)
            ->get()
            ->keyBy('schedule_id');

        return TripSchedule::query()
            ->where('trip_id', $tripId)
            ->where('status', 'open')
            ->where('id', '!=', $booking->schedule_id)
            ->whereDate('departure_date', '>=', now(self::TIMEZONE)->toDateString())
            ->whereDate('departure_date', '<=', $booking->force_majeure_until->toDateString())
            ->withHeldSeats()
            ->orderBy('departure_date')
            ->get()
            ->each->syncBookedSeats()
            ->map(function (TripSchedule $schedule) use ($booking, $pax, $holds) {
                $hold = $holds->get($schedule->id);
                $seatsLeft = $booking->is_join_trip
                    ? ($schedule->join_trip_enabled ? (int) ($schedule->join_trip_available_seats ?? $pax) : 0)
                    : $schedule->bookable_seats + (int) ($hold?->seat_count ?? 0);

                return [
                    'schedule' => $schedule,
                    'seats_left' => $seatsLeft,
                    'fits' => $seatsLeft >= $pax,
                    'hold' => $hold,
                ];
            })
            ->values();
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
                // จำไว้เผื่อทีมงานกดผิดรอบแล้วต้องย้อนกลับ (revertSchedule)
                'force_majeure_prev_status' => $schedule->status,
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
            // จดไว้ว่าปิดเพราะการเลื่อนนี้ — ย้อนกลับแล้วคืนคิวให้เฉพาะคนกลุ่มนี้
            // (ไม่ใช่คนที่ออกจากคิวเองก่อนหน้า)
            WaitlistEntry::whereKey($waitlist->modelKeys())->update([
                'status' => 'cancelled',
                'force_majeure_closed_at' => now(),
            ]);

            return [$schedule, $bookings, $waitlist];
        });

        foreach ($bookings as $booking) {
            $this->notifyPostponed($booking->fresh(['user', 'passengers', 'schedule.trip']));
        }

        $this->notifyWaitlist($schedule, $waitlist);
        $this->notifyStaff($schedule, $bookings->count());
        $this->chatEvents->roundPostponed($schedule, ThaiDate::full(self::windowEndFor($schedule)));

        return [
            'bookings' => $bookings->count(),
            'until' => self::windowEndFor($schedule)->toDateString(),
        ];
    }

    /**
     * ทำไมย้อนการเลื่อนไม่ได้ — null = ย้อนได้
     *
     * ย้อนได้เฉพาะตอนที่ยังไม่มีใครใช้สิทธิ์ย้ายไปรอบอื่น เพราะใบที่ย้ายไปแล้วกลับ
     * มารอบเดิมเองไม่ได้ (ที่นั่งรอบใหม่ถูกจองไปแล้ว เงินถูกโยกตามแล้ว)
     */
    public function revertBlockedReason(TripSchedule $schedule): ?string
    {
        if ($schedule->force_majeure_at === null) {
            return 'รอบนี้ไม่ได้ถูกเลื่อนเพราะเหตุสุดวิสัย';
        }

        // รอบที่ทีมงานตั้งเป็น "ยกเลิก" ไว้เองตั้งแต่ก่อนกดเลื่อน ย้อนกลับก็ยังเป็นรอบ
        // ที่ไม่ออก — แจ้งลูกค้าว่า "เดินทางตามเดิม" ไม่ได้ และใบจองจะค้างไร้สิทธิ์
        if ($schedule->force_majeure_prev_status === 'cancelled') {
            return 'รอบนี้ถูกตั้งเป็น "ยกเลิก" ไว้ตั้งแต่ก่อนกดเลื่อน ย้อนแล้วลูกค้าจะค้างในรอบที่ไม่ออก — ย้ายการจองหรือติดต่อลูกค้าเป็นรายคนแทน';
        }

        $moved = Booking::where('force_majeure_schedule_id', $schedule->id)
            ->whereNotNull('force_majeure_resolved_at')
            ->count();

        return $moved > 0
            ? "มีลูกค้า {$moved} รายการเลือกรอบใหม่ไปแล้ว ย้อนการเลื่อนไม่ได้ — ติดต่อลูกค้าที่เหลือเป็นรายคนแทน"
            : null;
    }

    /**
     * ย้อนการเลื่อน (ทีมงานกดผิดรอบ) — รอบกลับเป็นสถานะเดิม สิทธิ์เลือกรอบใหม่ถูกถอน
     * ที่นั่งที่กันไว้ในรอบอื่นถูกปล่อย คิวรอที่ถูกปิดได้คืน และแจ้งทุกคนที่ได้ข่าว
     * การเลื่อนไปแล้วว่าเดินทางตามเดิม
     *
     * @return array{bookings: int}
     */
    public function revertSchedule(TripSchedule $schedule): array
    {
        [$schedule, $bookings, $waitlist, $postponedAt, $holdSchedules] = DB::transaction(function () use ($schedule) {
            $schedule = TripSchedule::with('trip')->lockForUpdate()->findOrFail($schedule->id);

            // ล็อกทุกใบของการเลื่อนนี้ก่อนตัดสิน — ลูกค้าที่กำลังกดเลือกรอบอยู่
            // พอดีต้องไม่หลุดรอดไปเป็นใบที่ย้ายแล้วแต่สิทธิ์ถูกถอน
            $bookings = Booking::where('force_majeure_schedule_id', $schedule->id)
                ->lockForUpdate()
                ->get();

            if ($reason = $this->revertBlockedReason($schedule)) {
                throw new \Exception($reason);
            }

            $postponedAt = (int) $schedule->force_majeure_at->timestamp;
            $awaiting = $bookings->filter(fn (Booking $b) => $b->awaitsNewRound())->values();

            $holdSchedules = ForceMajeureSeatHold::whereIn('booking_id', $bookings->modelKeys())
                ->whereNull('released_at')
                ->pluck('schedule_id')
                ->unique()
                ->values();
            ForceMajeureSeatHold::whereIn('booking_id', $bookings->modelKeys())
                ->whereNull('released_at')
                ->update(['released_at' => now()]);

            foreach ($bookings as $booking) {
                $booking->forceFill([
                    'force_majeure_at' => null,
                    'force_majeure_reason' => null,
                    'force_majeure_until' => null,
                    'force_majeure_schedule_id' => null,
                    'force_majeure_resolved_at' => null,
                ])->save();
            }

            // saveQuietly: การกลับเป็น "เปิดรับจอง" ไม่ใช่รอบใหม่ — ห้ามให้ observer
            // ยิงประกาศ "เปิดรอบใหม่" ไปหาลูกค้าทั้งระบบ
            $schedule->forceFill([
                'status' => $schedule->force_majeure_prev_status ?: 'open',
                'force_majeure_at' => null,
                'force_majeure_reason' => null,
                'force_majeure_prev_status' => null,
            ])->saveQuietly();
            $schedule->syncBookedSeats();

            $waitlist = WaitlistEntry::where('schedule_id', $schedule->id)
                ->whereNotNull('force_majeure_closed_at')
                ->where('status', 'cancelled')
                ->get();
            WaitlistEntry::whereKey($waitlist->modelKeys())->update([
                'status' => 'waiting',
                'offered_at' => null,
                'expires_at' => null,
                'force_majeure_closed_at' => null,
            ]);

            return [$schedule, $awaiting, $waitlist, $postponedAt, $holdSchedules];
        });

        foreach ($bookings as $booking) {
            $this->notifyResumed($booking->fresh(['user', 'passengers', 'schedule.trip']), $postponedAt);
        }

        $this->notifyWaitlistResumed($schedule, $waitlist);
        $this->notifyStaffResumed($schedule);
        $this->chatEvents->roundResumed($schedule, $postponedAt);

        // ที่นั่งที่เคยกันไว้ในรอบอื่นกลับสู่คิว/สาธารณะ และคิวของรอบนี้ได้สิทธิ์ต่อ
        foreach ($holdSchedules as $scheduleId) {
            ProcessWaitlistJob::dispatch((int) $scheduleId);
        }
        ProcessWaitlistJob::dispatch($schedule->id);

        return ['bookings' => $bookings->count()];
    }

    /**
     * ปล่อยที่นั่งที่กันไว้ให้ใบนี้ (เลือกรอบแล้ว / ยกเลิก / ถูกย้าย) แล้วให้คิวรอของ
     * รอบนั้นได้สิทธิ์ต่อ — ยกเว้นรอบที่ใบนี้เพิ่งย้ายเข้าไปนั่งเอง
     */
    public function releaseHolds(Booking $booking, ?int $exceptScheduleId = null): void
    {
        $holds = ForceMajeureSeatHold::where('booking_id', $booking->id)
            ->whereNull('released_at')
            ->get();

        if ($holds->isEmpty()) {
            return;
        }

        ForceMajeureSeatHold::whereKey($holds->modelKeys())->update(['released_at' => now()]);

        foreach ($holds->pluck('schedule_id')->unique() as $scheduleId) {
            if ((int) $scheduleId !== (int) $exceptScheduleId) {
                ProcessWaitlistJob::dispatch((int) $scheduleId);
            }
        }
    }

    /**
     * การกันที่นั่งที่หมดเวลาแล้ว — ที่นั่งกลับสู่คนทั่วไปเองตามเวลาอยู่แล้ว
     * (heldSeats ไม่นับ) ตรงนี้แค่ปิดบัญชีและให้คิวรอของรอบนั้นได้สิทธิ์ต่อทันที
     */
    public function releaseExpiredHolds(): int
    {
        $expired = ForceMajeureSeatHold::whereNull('released_at')
            ->where('expires_at', '<=', now())
            ->get();

        if ($expired->isEmpty()) {
            return 0;
        }

        ForceMajeureSeatHold::whereKey($expired->modelKeys())->update(['released_at' => now()]);

        foreach ($expired->pluck('schedule_id')->unique() as $scheduleId) {
            ProcessWaitlistJob::dispatch((int) $scheduleId);
        }

        return $expired->count();
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
        $this->releaseHolds($booking, (int) $booking->schedule_id);
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
        // คนที่ถูกเลื่อนก่อนได้ก่อน — กันที่นั่งตามลำดับเวลาที่รอบเดิมของเขาถูกเลื่อน
        $bookings = Booking::query()
            ->awaitingNewRound()
            ->whereIn('status', Booking::MODIFIABLE_STATUSES)
            ->whereNotNull('user_id')
            ->whereDate('force_majeure_until', '>=', $date)
            ->whereHas('forceMajeureSchedule', fn ($q) => $q->where('trip_id', $schedule->trip_id))
            ->withCount('passengers')
            ->orderBy('force_majeure_at')
            ->orderBy('id')
            ->get();

        // ที่ว่างจริงหลังหักที่ถูกกันไว้แล้ว (คิวรอที่ได้สิทธิ์ / การกันรอบก่อน)
        $free = max(0, $schedule->available_seats - app(WaitlistService::class)->heldSeats($schedule->id));
        $holdUntil = now()->addHours(self::HOLD_HOURS);
        $sent = 0;

        foreach ($bookings as $booking) {
            $pax = max(1, (int) $booking->passengers_count);

            // รอบที่ที่นั่งไม่พอสำหรับทั้งกลุ่ม ชวนไปก็กดเลือกไม่ได้
            if (! $booking->is_join_trip && $free < $pax) {
                continue;
            }

            $alreadySent = SmartNotification::where('user_id', $booking->user_id)
                ->where('type', 'trip_postponed_new_round')
                ->where('data->booking_ref', $booking->booking_ref)
                ->where('data->schedule_id', $schedule->id)
                ->where('data->episode', $booking->force_majeure_at?->timestamp)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            // จอยทริปไม่ได้กินที่นั่งบนรถ — ไม่ต้องกัน แค่บอก
            $hold = null;
            if (! $booking->is_join_trip) {
                $hold = ForceMajeureSeatHold::firstOrCreate(
                    ['schedule_id' => $schedule->id, 'booking_id' => $booking->id],
                    ['user_id' => $booking->user_id, 'seat_count' => $pax, 'expires_at' => $holdUntil],
                );
                if ($hold->wasRecentlyCreated) {
                    $free -= $pax;
                } else {
                    $hold = null;
                }
            }

            SmartNotification::send(
                $booking->user_id,
                'trip_postponed_new_round',
                'เปิดรอบใหม่แล้ว เลือกได้เลย',
                'ทริป '.($schedule->trip?->title ?? '').' เปิดรอบ '.ThaiDate::short($schedule->departure_date)
                    .' แล้ว ใช้สิทธิ์เลือกรอบใหม่ของการจอง '.$booking->booking_ref.' ได้ ราคาเดิม'
                    .($hold ? ' · เรากันที่นั่งไว้ให้ '.$pax.' ที่ ถึง '.ThaiDate::shortTime($hold->expires_at->copy()->timezone(self::TIMEZONE)).' น.' : ''),
                [
                    'booking_ref' => $booking->booking_ref,
                    'schedule_id' => $schedule->id,
                    'episode' => $booking->force_majeure_at?->timestamp,
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
                    ->where('data->episode', $booking->force_majeure_at?->timestamp)
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
                        'episode' => $booking->force_majeure_at?->timestamp,
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
            // กดผิดรอบ — ย้อนได้เมื่อยังไม่มีใครใช้สิทธิ์ย้ายไปรอบอื่น
            'can_revert' => $schedule->force_majeure_at !== null && $this->revertBlockedReason($schedule) === null,
            'revert_blocked_reason' => $schedule->force_majeure_at !== null ? $this->revertBlockedReason($schedule) : null,
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
            // รอบใหม่ที่กันที่นั่งไว้ให้ใบนี้ — หน้าจอบวกกลับเข้าไปในที่ว่างของรอบนั้น
            // (ตัวเลขที่นั่งสาธารณะหักที่ที่กันไว้ออกแล้ว รวมของเจ้าของเองด้วย)
            'holds' => $awaiting
                ? ForceMajeureSeatHold::active()
                    ->where('booking_id', $booking->id)
                    ->get()
                    ->map(fn (ForceMajeureSeatHold $hold) => [
                        'schedule_id' => $hold->schedule_id,
                        'seat_count' => $hold->seat_count,
                        'expires_at' => $hold->expires_at->toISOString(),
                        'expires_label' => ThaiDate::shortTime($hold->expires_at->copy()->timezone(self::TIMEZONE)).' น.',
                    ])
                    ->values()
                    ->all()
                : [],
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
            // ส่งให้ลูกค้าทางไลน์/SMS เองได้ — เปิดแล้วเลือกรอบได้โดยไม่ต้องล็อกอิน
            'choose_url' => $state === 'awaiting' ? $booking->rescheduleUrl() : null,
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

    private function notifyResumed(Booking $booking, int $postponedAt): void
    {
        $schedule = $booking->schedule;

        if ($booking->user_id) {
            try {
                SmartNotification::send(
                    $booking->user_id,
                    'trip_resumed',
                    'รอบเดินทางเป็นไปตามกำหนดเดิม',
                    'ขออภัยในความสับสนครับ '.($schedule?->trip?->title ?? 'ทริป').' วันที่ '
                        .ThaiDate::short($schedule?->departure_date)
                        .' เดินทางตามกำหนดเดิม ข้อความเรื่องเลื่อนรอบก่อนหน้านี้ส่งผิด ไม่ต้องเลือกรอบใหม่',
                    [
                        'booking_ref' => $booking->booking_ref,
                        'schedule_id' => $schedule?->id,
                        'route' => 'booking',
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('Force majeure resume push failed', [
                    'booking_ref' => $booking->booking_ref,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->mail->sendTripResumedEmail($booking);
        $this->sms->sendTripResumed($booking, $postponedAt);
    }

    private function notifyWaitlistResumed(TripSchedule $schedule, Collection $entries): void
    {
        foreach ($entries as $entry) {
            try {
                SmartNotification::send(
                    $entry->user_id,
                    'waitlist_round_reopened',
                    'รอบที่คุณรอคิวกลับมาแล้ว',
                    ($schedule->trip?->title ?? 'ทริป').' รอบ '.ThaiDate::short($schedule->departure_date)
                        .' เดินทางตามกำหนดเดิม คิวรอที่นั่งของคุณยังอยู่ ถ้ามีที่ว่างระบบจะแจ้งทันที',
                    [
                        'trip_slug' => $schedule->trip?->slug,
                        'schedule_id' => $schedule->id,
                        'route' => 'trip',
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('Force majeure waitlist resume push failed', ['message' => $e->getMessage()]);
            }
        }
    }

    private function notifyStaffResumed(TripSchedule $schedule): void
    {
        foreach ($schedule->activeStaff()->get() as $staff) {
            try {
                SmartNotification::send(
                    $staff->id,
                    'schedule_resumed',
                    'รอบเดินทางกลับมาตามกำหนด',
                    ($schedule->trip?->title ?? 'ทริป').' รอบ '.ThaiDate::short($schedule->departure_date)
                        .' ยกเลิกการเลื่อนแล้ว เดินทางตามกำหนดเดิม',
                    ['schedule_id' => $schedule->id],
                );
            } catch (\Throwable $e) {
                Log::warning('Force majeure staff resume push failed', ['message' => $e->getMessage()]);
            }
        }
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
