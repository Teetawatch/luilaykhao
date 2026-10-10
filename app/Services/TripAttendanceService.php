<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\SmartNotification;
use App\Models\User;
use App\Support\ThaiDate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "พรุ่งนี้ไปครบไหม" — ใครในใบจองแจ้งไว้ล่วงหน้าว่าไม่ไป
 *
 * ปัญหาที่แก้: ใบจอง 4 คนที่มาจริง 3 คน สตาฟยืนรอคนที่สี่ที่จุดรับโดยไม่มีใคร
 * รู้ว่าเขาไม่มา ตอนนี้คนจอง (หรือเจ้าตัวผ่านลิงก์ของเพื่อน) บอกไว้ก่อนได้
 * สตาฟเห็นป้าย "แจ้งไม่ไป" ในรายชื่อ และจุดรับนั้นไม่ค้างรอคนที่ไม่มา
 *
 * การแจ้งไม่ไปไม่ใช่การยกเลิก — ไม่คืนที่นั่ง ไม่คืนเงิน ถ้ามีคนไปแทน ให้ใช้
 * "ส่งต่อที่นั่ง" ซึ่งเปลี่ยนชื่อในใบจองให้ถูกต้อง (ประกันต้องตรงตัวคน)
 *
 * แก้ได้จนถึงเวลารถออก คนที่แจ้งไว้แล้วโผล่มาจริง สตาฟสแกนเช็คอินได้ตามปกติ
 * (PassengerCheckInService ล้างสถานะนี้ให้เอง)
 */
class TripAttendanceService
{
    public const TIMEZONE = 'Asia/Bangkok';

    /** แจ้งสตาฟเมื่อมีคนเปลี่ยนใจภายในกี่วันก่อนเดินทาง — ก่อนหน้านั้นยังไม่มีใครถือรายชื่อ */
    public const STAFF_NOTICE_DAYS = 2;

    public function __construct(private SosParticipantService $participants) {}

    /** ยังแจ้ง/แก้ได้ไหม */
    public function isOpen(Booking $booking): bool
    {
        return $this->closedReason($booking) === null;
    }

    public function closedReason(Booking $booking): ?string
    {
        if ($booking->status !== 'confirmed') {
            return 'การจองนี้ยังไม่ได้รับการยืนยัน หรือถูกยกเลิกแล้ว';
        }

        $schedule = $booking->schedule;

        if (! $schedule || $schedule->status === 'cancelled' || $booking->awaitsNewRound()) {
            return 'รอบเดินทางนี้ถูกเลื่อนหรือยกเลิกแล้ว';
        }

        $now = $this->nowThai();

        // departs_at เก็บเวลาไทยไว้ในคอลัมน์ชนิด UTC — เทียบเป็นตัวเลขเวลาไทยเท่านั้น
        // รอบที่ไม่เคยตั้งเวลาออกรถ แก้ได้ถึงสิ้นวันเดินทาง (เที่ยงคืนที่เติมให้ไม่ใช่เวลาจริง)
        $closed = $schedule->departs_at
            ? $now->format('Y-m-d H:i:s') >= $schedule->departs_at->format('Y-m-d H:i:s')
            : $now->toDateString() > $schedule->departure_date?->toDateString();

        return $closed ? 'รถออกเดินทางไปแล้ว แจ้งทีมงานในแชทได้เลย' : null;
    }

    /**
     * เจ้าตัว (หรือคนจอง) บอกว่าคนนี้ไป/ไม่ไป — คืน true เมื่อมีการเปลี่ยนแปลง
     *
     * @throws \Exception ข้อความภาษาไทยที่หน้าจอแสดงได้เลย
     */
    public function setNotGoing(Booking $booking, BookingPassenger $passenger, bool $notGoing): bool
    {
        if ((int) $passenger->booking_id !== (int) $booking->id) {
            throw new \Exception('ไม่พบผู้เดินทางคนนี้ในการจอง');
        }

        if ($reason = $this->closedReason($booking)) {
            throw new \Exception($reason);
        }

        if ($passenger->isCheckedIn()) {
            throw new \Exception($passenger->displayName().' เช็คอินขึ้นรถแล้ว');
        }

        if ($notGoing === ($passenger->not_going_at !== null)) {
            return false;
        }

        $passenger->forceFill(['not_going_at' => $notGoing ? now() : null])->save();

        $this->notifyCrew($booking, $notGoing ? collect([$passenger]) : collect(), $notGoing ? collect() : collect([$passenger]));

        return true;
    }

    /**
     * คนจองตอบคำถาม "ไปครบไหม" ทั้งใบ — $notGoingIds คือคนที่ไม่ไป ที่เหลือไปทั้งหมด
     *
     * @param  array<int, int>  $notGoingIds
     *
     * @throws \Exception
     */
    public function confirm(Booking $booking, array $notGoingIds): void
    {
        if ($reason = $this->closedReason($booking)) {
            throw new \Exception($reason);
        }

        $notGoingIds = array_values(array_unique(array_map('intval', $notGoingIds)));
        $passengers = BookingPassenger::where('booking_id', $booking->id)->orderBy('id')->get();

        if (array_diff($notGoingIds, $passengers->pluck('id')->map(fn ($id) => (int) $id)->all()) !== []) {
            throw new \Exception('มีผู้เดินทางที่ไม่ได้อยู่ในการจองนี้');
        }

        $nowNotGoing = collect();
        $nowGoing = collect();

        DB::transaction(function () use ($booking, $passengers, $notGoingIds, &$nowNotGoing, &$nowGoing) {
            foreach ($passengers as $passenger) {
                // คนที่ขึ้นรถแล้วคือคนที่ไปแน่ ๆ — ไม่มีอะไรให้แก้
                if ($passenger->isCheckedIn()) {
                    continue;
                }

                $wantsOut = in_array((int) $passenger->id, $notGoingIds, true);

                if ($wantsOut && $passenger->not_going_at === null) {
                    $passenger->forceFill(['not_going_at' => now()])->save();
                    $nowNotGoing->push($passenger);
                } elseif (! $wantsOut && $passenger->not_going_at !== null) {
                    $passenger->forceFill(['not_going_at' => null])->save();
                    $nowGoing->push($passenger);
                }
            }

            // saveQuietly: การตอบคำถามไม่ใช่การเปลี่ยนแปลงของการจอง ไม่ต้องปลุกงาน
            // เบื้องหลังที่เฝ้าดูใบจองอยู่
            $booking->forceFill(['attendance_confirmed_at' => now()])->saveQuietly();
        });

        $this->notifyCrew($booking, $nowNotGoing, $nowGoing);
    }

    /**
     * สถานะของใบจองนี้สำหรับหน้าจอ — คนที่ดูเป็นเจ้าของแก้ได้ทุกคน เพื่อนแก้ได้แค่ตัวเอง
     *
     * @return array<string, mixed>
     */
    public function summary(Booking $booking, ?User $viewer): array
    {
        $passengers = BookingPassenger::where('booking_id', $booking->id)->orderBy('id')->get();
        $isOwner = $viewer && (int) $booking->user_id === (int) $viewer->id;

        $mineId = null;
        if ($viewer && ! $isOwner) {
            $mineId = BookingMember::where('booking_id', $booking->id)
                ->where('user_id', $viewer->id)
                ->where('status', BookingMember::STATUS_ACTIVE)
                ->value('passenger_id');
        }

        $going = $passengers->filter(fn (BookingPassenger $p) => ! $p->isNotGoing())->count();

        return [
            'booking_ref' => $booking->booking_ref,
            'open' => $this->isOpen($booking),
            'closed_reason' => $this->closedReason($booking),
            'asked_at' => $booking->attendance_asked_at?->toIso8601String(),
            'confirmed_at' => $booking->attendance_confirmed_at?->toIso8601String(),
            'viewer_is_owner' => $isOwner,
            'going_count' => $going,
            'total' => $passengers->count(),
            'passengers' => $passengers->map(fn (BookingPassenger $p) => [
                'id' => $p->id,
                'name' => $p->displayName(),
                'full_name' => trim(($p->title ? $p->title.' ' : '').$p->name),
                'not_going' => $p->isNotGoing(),
                'checked_in' => $p->isCheckedIn(),
                'is_mine' => $mineId !== null && (int) $mineId === (int) $p->id,
                'can_edit' => ($isOwner || ($mineId !== null && (int) $mineId === (int) $p->id))
                    && ! $p->isCheckedIn(),
            ])->values()->all(),
        ];
    }

    /**
     * บอกสตาฟ/คนขับของรอบ ถ้าใกล้วันเดินทางแล้ว — ก่อนหน้านั้นรายชื่อยังไม่ได้อยู่ในมือใคร
     *
     * @param  Collection<int, BookingPassenger>  $out
     * @param  Collection<int, BookingPassenger>  $backIn
     */
    private function notifyCrew(Booking $booking, Collection $out, Collection $backIn): void
    {
        if ($out->isEmpty() && $backIn->isEmpty()) {
            return;
        }

        $schedule = $booking->schedule;
        $departure = $schedule?->effectiveDepartureDate();

        if (! $schedule || ! $departure) {
            return;
        }

        $today = $this->nowThai()->startOfDay();
        $daysAway = $today->diffInDays(Carbon::parse($departure->toDateString()), false);

        if ($daysAway > self::STAFF_NOTICE_DAYS || $daysAway < 0) {
            return;
        }

        $crew = $this->participants->staffIds($schedule)
            ->push($this->participants->driverId($schedule))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        if ($crew->isEmpty()) {
            return;
        }

        $names = fn (Collection $people) => $people->map(fn ($p) => $p->displayName())->join(', ');
        $when = ThaiDate::short($departure);
        $trip = $schedule->trip?->title ?? 'ทริป';

        $parts = [];
        if ($out->isNotEmpty()) {
            $parts[] = 'ไม่ไป: '.$names($out);
        }
        if ($backIn->isNotEmpty()) {
            $parts[] = 'กลับมาไป: '.$names($backIn);
        }

        $title = $out->isNotEmpty() ? 'ผู้เดินทางแจ้งไม่ไป' : 'ผู้เดินทางกลับมาไปได้';
        $body = implode(' · ', $parts)." — {$trip} {$when} · {$booking->booking_ref}";

        foreach ($crew as $userId) {
            SmartNotification::send(
                $userId,
                'passenger_not_going',
                $title,
                $body,
                [
                    'booking_ref' => $booking->booking_ref,
                    'schedule_id' => $schedule->id,
                    'route' => 'staff_manifest',
                ],
            );
        }
    }

    private function nowThai(): Carbon
    {
        return Carbon::parse(now(self::TIMEZONE)->format('Y-m-d H:i:s'));
    }
}
