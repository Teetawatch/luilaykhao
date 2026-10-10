<?php

namespace App\Services;

use App\Jobs\AnnounceSeatHandoverJob;
use App\Jobs\SyncTripActivityJob;
use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\BookingSeat;
use App\Models\BookingSplitShare;
use App\Models\SeatHandover;
use App\Models\SmartNotification;
use App\Models\User;
use App\Support\MediaDisk;
use App\Support\ThaiDate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ส่งต่อที่นั่ง — คนที่ไปไม่ได้ส่งลิงก์ให้คนอื่นมารับที่นั่งไปแทน
 *
 * เกิดจากเคสจริง: ลูกค้าทักมาคืนก่อนเดินทางว่าน้ำท่วมบ้าน ไปไม่ได้สองที่นั่ง
 * ยกเลิกตอนนี้ก็ไม่ได้เงินคืน (น้อยกว่า 3 วัน) ที่นั่งก็ว่างเปล่า ทางที่ดีที่สุด
 * สำหรับทุกฝ่ายคือหาคนไปแทน แต่เดิมต้องให้ทีมงานแก้รายชื่อให้ทีละคน และคนใหม่ก็
 * ไม่ได้อยู่ในห้องแชท/การ์ดวันเดินทางเพราะไม่ได้ผูกกับบัญชีไหน
 *
 * หลักการ
 * - ลิงก์หนึ่งอัน = ที่นั่งหนึ่งที่ (ผู้เดินทางหนึ่งคน) คนรับกรอกข้อมูลของตัวเอง
 *   เจ้าของการจองไม่ต้องรู้เลขบัตรประชาชนของคนที่มาแทน
 * - ข้อมูลส่วนตัวของคนเดิมถูกเขียนทับทั้งหมด ไม่ผสม (เลขบัตร/โรคประจำตัวของคน
 *   เดิมห้ามค้างไปอยู่กับชื่อคนใหม่ — รายชื่อนี้ส่งทำประกัน) เอกสารแนบของคนเดิมถูกลบ
 * - คนรับเข้ามาเป็นสมาชิกของใบจอง (BookingMember) จึงได้แชท วันเดินทาง SOS ทันที
 *   คนเดิมที่ผูกกับที่นั่งนี้ถูกถอดออก
 * - ถ้าที่นั่งที่ส่งต่อคือที่นั่งของ "เจ้าของการจอง" เอง ความเป็นเจ้าของต้องย้ายตาม
 *   ไปด้วย ไม่งั้นเจ้าของเดิมยังนับเป็นคนในทริป (แชท/SOS/การ์ด/เหรียญ) ทั้งที่ไม่ได้ไป
 *   — ทางนี้บังคับว่าชำระครบแล้ว เพราะคนรับไม่ควรรับหนี้ที่ไม่รู้ตัว
 * - เงินระหว่างคนเดิมกับคนรับเป็นเรื่องที่ตกลงกันเอง ระบบไม่คืนเงินและไม่เก็บเพิ่ม
 */
class SeatHandoverService
{
    /** ปิดรับก่อนรถออก — ทีมงานต้องมีเวลาเห็นรายชื่อใหม่ก่อนออกไปรับคน */
    public const CLOSE_HOURS_BEFORE_DEPARTURE = 3;

    /** ลิงก์อายุไม่เกินนี้ แม้ทริปจะอีกหลายเดือน — กันลิงก์ค้างในแชทกลุ่มเป็นเดือน */
    public const LINK_TTL_DAYS = 14;

    public const WOMEN_ONLY_TITLES = ['นาง', 'นางสาว'];

    private const TIMEZONE = 'Asia/Bangkok';

    /** ช่องข้อมูลส่วนตัวที่คนรับกรอกเอง — ทุกช่องถูกเขียนทับ ไม่มีของคนเดิมเหลือ */
    private const PERSONAL_FIELDS = [
        'title', 'name', 'nickname', 'nationality', 'id_card', 'birth_date',
        'phone', 'email', 'blood_group', 'allergies', 'health_notes', 'halal_food',
        'emergency_contact', 'emergency_phone', 'name_en', 'passport_no', 'passport_expires_at',
    ];

    public function __construct(private SosParticipantService $participants) {}

    /* ------------------------------------------------------------------ */
    /*  เวลา */
    /* ------------------------------------------------------------------ */

    /**
     * เส้นตายส่งต่อที่นั่ง เป็นเวลาไทยในรูปเดียวกับ departs_at (ตัวเลขเวลาไทยที่ติด
     * ป้าย UTC) — เทียบกับ nowThai() เท่านั้น ห้ามเทียบกับ now()
     *
     * รู้เวลารถออก → ก่อนรถออก CLOSE_HOURS_BEFORE_DEPARTURE ชั่วโมง
     * ไม่รู้ (departs_at ว่าง) → นับเป็นวัน: ปิดเมื่อขึ้นวันเดินทาง เที่ยงคืนที่
     * effectiveDepartsAt() เติมให้ใช้เทียบวันได้ แต่ห้ามเอาไปลบชั่วโมง
     */
    public function deadline(Booking $booking): ?Carbon
    {
        $schedule = $booking->schedule;
        if (! $schedule) {
            return null;
        }

        if ($schedule->departs_at) {
            return $schedule->departs_at->copy()->subHours(self::CLOSE_HOURS_BEFORE_DEPARTURE);
        }

        return $schedule->departure_date?->copy()->startOfDay();
    }

    /** เส้นตายเป็นเวลาจริง (UTC) — สำหรับ expires_at และส่งให้ client นับถอยหลัง */
    public function deadlineInstant(Booking $booking): ?Carbon
    {
        $deadline = $this->deadline($booking);

        return $deadline ? $this->thaiToInstant($deadline) : null;
    }

    public function deadlineLabel(Booking $booking): ?string
    {
        $deadline = $this->deadline($booking);
        if (! $deadline) {
            return null;
        }

        if ($booking->schedule?->departs_at) {
            return ThaiDate::full($deadline).' เวลา '.$deadline->format('H:i').' น.';
        }

        return 'สิ้นวันที่ '.ThaiDate::full($deadline->copy()->subDay());
    }

    private function nowThai(): Carbon
    {
        return Carbon::parse(now(self::TIMEZONE)->format('Y-m-d H:i:s'));
    }

    private function thaiToInstant(Carbon $thaiWallClock): Carbon
    {
        return Carbon::parse($thaiWallClock->format('Y-m-d H:i:s'), self::TIMEZONE)->utc();
    }

    /* ------------------------------------------------------------------ */
    /*  สิทธิ์ */
    /* ------------------------------------------------------------------ */

    /**
     * เหตุผลที่ใบจองนี้ส่งต่อที่นั่งไม่ได้ — null คือได้
     *
     * $byStaff: ทีมงานออกลิงก์ให้ลูกค้า ข้ามได้สองข้อ — รอบเครื่องบิน (ทีมงานรู้ว่า
     * ต้องไปเปลี่ยนชื่อตั๋วกับสายการบินเอง) และเส้นตาย 3 ชั่วโมง (ทีมงานเห็นรายชื่อ
     * ใหม่อยู่แล้ว) แต่ยังต้องก่อนรถออกจริง
     */
    public function blockedReason(Booking $booking, bool $byStaff = false): ?string
    {
        $schedule = $booking->schedule;

        if ($booking->status === 'pending') {
            return 'ต้องชำระเงินให้การจองได้รับการยืนยันก่อน จึงจะส่งต่อที่นั่งได้';
        }

        if ($booking->status !== 'confirmed') {
            return 'การจองนี้ส่งต่อที่นั่งไม่ได้แล้ว';
        }

        if ($booking->is_gift && $booking->gift_claimed_at === null) {
            return 'การจองนี้เป็นของขวัญที่ยังไม่มีคนรับ — ส่งโค้ดของขวัญให้คนที่จะไปได้เลย';
        }

        if (! $schedule || $schedule->status === 'cancelled') {
            return $booking->awaitsNewRound()
                ? 'รอบนี้ถูกเลื่อน กรุณาเลือกรอบใหม่ก่อน แล้วค่อยส่งต่อที่นั่งในรอบใหม่'
                : 'รอบเดินทางนี้ถูกยกเลิกแล้ว';
        }

        if ($booking->awaitsNewRound()) {
            return 'รอบนี้ถูกเลื่อน กรุณาเลือกรอบใหม่ก่อน แล้วค่อยส่งต่อที่นั่งในรอบใหม่';
        }

        if ($booking->checked_in) {
            return 'เช็คอินขึ้นรถแล้ว ส่งต่อที่นั่งไม่ได้';
        }

        if (! $byStaff && $schedule->isFlight()) {
            return 'รอบที่เดินทางด้วยเครื่องบินเปลี่ยนชื่อผู้เดินทางเองไม่ได้ เพราะตั๋วออกในชื่อเดิมแล้ว '
                .'ทักทีมงานในแชทเพื่อให้ช่วยดำเนินการได้เลยครับ';
        }

        $deadline = $byStaff ? $schedule->effectiveDepartsAt() : $this->deadline($booking);
        if ($deadline === null || $this->nowThai()->gte($deadline)) {
            return $byStaff
                ? 'รอบนี้ออกเดินทางไปแล้ว'
                : 'เลยเวลาส่งต่อที่นั่งแล้ว (ปิดก่อนรถออก '.self::CLOSE_HOURS_BEFORE_DEPARTURE.' ชั่วโมง) '
                    .'ทักทีมงานในแชทได้เลยครับ';
        }

        return null;
    }

    /**
     * บทบาทของผู้ใช้กับใบจองนี้: owner = จัดการได้ทุกที่นั่ง, member = เฉพาะที่นั่ง
     * ของตัวเอง (passenger ที่ผูกไว้) — null = ไม่เกี่ยว
     *
     * @return array{role: string, passenger_id: int|null}|null
     */
    public function viewerRole(Booking $booking, ?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        if ((int) $booking->user_id === (int) $user->id) {
            return ['role' => 'owner', 'passenger_id' => null];
        }

        $member = BookingMember::where('booking_id', $booking->id)
            ->where('user_id', $user->id)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->first();

        if (! $member) {
            return null;
        }

        return ['role' => 'member', 'passenger_id' => $member->passenger_id ? (int) $member->passenger_id : null];
    }

    /**
     * สรุปสั้นสำหรับ BookingResource — หน้าจอใช้ตัดสินว่าจะโชว์ปุ่ม "ส่งต่อที่นั่ง" ไหม
     *
     * @return array<string, mixed>|null
     */
    public function summaryFor(Booking $booking, ?User $viewer): ?array
    {
        $role = $this->viewerRole($booking, $viewer);
        if ($role === null) {
            return null;
        }

        // สมาชิกที่ไม่ได้ผูกกับที่นั่งใดเลย (รับคำเชิญแบบไม่ระบุคน) ไม่มีที่นั่งให้ส่ง
        if ($role['role'] === 'member' && $role['passenger_id'] === null) {
            return null;
        }

        $reason = $this->blockedReason($booking);

        return [
            'available' => $reason === null,
            'blocked_reason' => $reason,
            'deadline' => $this->deadlineInstant($booking)?->toISOString(),
            'deadline_label' => $this->deadlineLabel($booking),
            'open_count' => $reason === null
                ? $this->openHandovers($booking)
                    ->when($role['role'] === 'member', fn ($c) => $c->where('passenger_id', $role['passenger_id']))
                    ->count()
                : 0,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  ฝั่งคนส่ง */
    /* ------------------------------------------------------------------ */

    /**
     * ทุกอย่างที่หน้า "ส่งต่อที่นั่ง" ต้องวาด — ที่นั่งที่ผู้ใช้คนนี้จัดการได้ ลิงก์ที่
     * เปิดค้าง และประวัติ
     *
     * @return array<string, mixed>
     */
    public function overview(Booking $booking, ?User $viewer, bool $asStaff = false): array
    {
        $booking->loadMissing(['schedule.trip', 'passengers', 'seats', 'user']);
        $role = $asStaff ? ['role' => 'staff', 'passenger_id' => null] : $this->viewerRole($booking, $viewer);

        if ($role === null) {
            throw new \Exception('ไม่พบการจองนี้');
        }

        $reason = $this->blockedReason($booking, $asStaff);
        $canManageAll = $role['role'] !== 'member';
        $isFullyPaid = $booking->isFullyPaid();
        $openOwnership = $this->openHandovers($booking)->firstWhere('transfers_ownership', true);
        $handovers = $booking->seatHandovers()->with(['creator', 'claimer'])->latest('id')->get();
        $ownerGuess = $this->ownerSeatGuess($booking);
        $members = BookingMember::where('booking_id', $booking->id)
            ->whereIn('status', [BookingMember::STATUS_ACTIVE, BookingMember::STATUS_PENDING])
            ->whereNotNull('passenger_id')
            ->with('user:id,name,nickname')
            ->get()
            ->keyBy('passenger_id');

        $seats = $booking->passengers->sortBy('id')->values()
            ->filter(fn (BookingPassenger $p) => $canManageAll || (int) $p->id === $role['passenger_id'])
            ->map(function (BookingPassenger $passenger) use ($booking, $handovers, $members, $ownerGuess, $reason, $role) {
                $open = $handovers->first(
                    fn (SeatHandover $h) => (int) $h->passenger_id === (int) $passenger->id && $h->isOpen(),
                );
                $member = $members->get($passenger->id);

                return [
                    'passenger_id' => $passenger->id,
                    'name' => $passenger->name,
                    'nickname' => $passenger->nickname,
                    'seat_label' => $this->seatFor($booking, $passenger)?->seat_id,
                    'is_owner_seat_guess' => $ownerGuess === (int) $passenger->id,
                    'is_mine' => $role['role'] === 'member' && (int) $passenger->id === $role['passenger_id'],
                    'member_name' => $member?->status === BookingMember::STATUS_ACTIVE
                        ? ($member->user?->nickname ?: $member->user?->name)
                        : null,
                    'can_hand_over' => $reason === null,
                    'open_handover' => $open ? $this->present($open, withLink: true) : null,
                ];
            })
            ->values()
            ->all();

        $visibleHistory = $handovers
            ->reject(fn (SeatHandover $h) => $h->isOpen())
            ->filter(fn (SeatHandover $h) => $canManageAll || (int) $h->passenger_id === $role['passenger_id'])
            ->take(20)
            ->map(fn (SeatHandover $h) => $this->present($h, withLink: false, withAudit: $asStaff))
            ->values()
            ->all();

        $ownershipReason = match (true) {
            $role['role'] === 'member' => 'เฉพาะเจ้าของการจองเท่านั้นที่โอนสิทธิ์ดูแลการจองได้',
            ! $isFullyPaid => 'ต้องชำระเงินให้ครบก่อน ถึงจะให้คนรับดูแลการจองแทนได้ — ระหว่างนี้ส่งต่อเฉพาะที่นั่งได้ (คุณยังเป็นผู้ดูแลและผู้ชำระเงิน)',
            $openOwnership !== null => 'มีลิงก์ที่โอนสิทธิ์ดูแลการจองค้างอยู่แล้ว ยกเลิกลิงก์นั้นก่อนถ้าจะออกใหม่',
            default => null,
        };

        return [
            'booking_ref' => $booking->booking_ref,
            'available' => $reason === null,
            'blocked_reason' => $reason,
            'deadline' => ($asStaff ? null : $this->deadlineInstant($booking))?->toISOString(),
            'deadline_label' => $asStaff ? null : $this->deadlineLabel($booking),
            'viewer_role' => $role['role'],
            'is_fully_paid' => $isFullyPaid,
            'can_transfer_ownership' => $ownershipReason === null,
            'ownership_blocked_reason' => $ownershipReason,
            'open_ownership_passenger_id' => $openOwnership?->passenger_id,
            'seats' => $seats,
            'history' => $visibleHistory,
            'trip_title' => $booking->schedule?->trip?->title,
            'departure_label' => $booking->schedule?->departureLabelThai(),
        ];
    }

    /**
     * ออกลิงก์ส่งต่อที่นั่งของผู้เดินทางหนึ่งคน — ลิงก์เก่าของที่นั่งเดียวกันถูกยกเลิก
     * (ส่งผิดคน/อยากส่งใหม่ ลิงก์เดิมที่หลุดไปแล้วต้องใช้ไม่ได้อีก)
     */
    public function create(
        Booking $booking,
        int $passengerId,
        User $creator,
        bool $transfersOwnership = false,
        ?string $note = null,
        bool $asStaff = false,
    ): SeatHandover {
        return DB::transaction(function () use ($booking, $passengerId, $creator, $transfersOwnership, $note, $asStaff) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $booking->load('schedule.trip');

            if ($reason = $this->blockedReason($booking, $asStaff)) {
                throw new \Exception($reason);
            }

            $passenger = BookingPassenger::where('booking_id', $booking->id)->whereKey($passengerId)->first();
            if (! $passenger) {
                throw new \Exception('ไม่พบผู้เดินทางคนนี้ในการจอง');
            }

            $viewer = $asStaff ? ['role' => 'staff', 'passenger_id' => null] : $this->viewerRole($booking, $creator);
            if ($viewer === null) {
                throw new \Exception('ไม่พบการจองนี้');
            }

            if ($viewer['role'] === 'member') {
                if ((int) $viewer['passenger_id'] !== (int) $passenger->id) {
                    throw new \Exception('คุณส่งต่อได้เฉพาะที่นั่งของตัวเองเท่านั้น');
                }
                if ($transfersOwnership) {
                    throw new \Exception('เฉพาะเจ้าของการจองเท่านั้นที่โอนสิทธิ์ดูแลการจองได้');
                }
            }

            if ($transfersOwnership) {
                if (! $booking->isFullyPaid()) {
                    throw new \Exception('ต้องชำระเงินให้ครบก่อน ถึงจะให้คนรับดูแลการจองแทนได้');
                }

                $otherOwnership = $this->openHandovers($booking)
                    ->first(fn (SeatHandover $h) => $h->transfers_ownership && (int) $h->passenger_id !== (int) $passenger->id);
                if ($otherOwnership) {
                    throw new \Exception('มีลิงก์ที่โอนสิทธิ์ดูแลการจองค้างอยู่แล้ว ยกเลิกลิงก์นั้นก่อนถ้าจะออกใหม่');
                }
            }

            SeatHandover::where('passenger_id', $passenger->id)
                ->where('status', SeatHandover::STATUS_PENDING)
                ->update([
                    'status' => SeatHandover::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancelled_by' => $creator->id,
                ]);

            $closesAt = $asStaff
                ? $this->thaiToInstant($booking->schedule->effectiveDepartsAt())
                : $this->deadlineInstant($booking);
            $expiresAt = now()->addDays(self::LINK_TTL_DAYS);
            if ($closesAt && $closesAt->lt($expiresAt)) {
                $expiresAt = $closesAt;
            }

            $note = $note !== null ? trim($note) : null;

            return SeatHandover::create([
                'booking_id' => $booking->id,
                'passenger_id' => $passenger->id,
                'token' => $this->generateToken(),
                'status' => SeatHandover::STATUS_PENDING,
                'transfers_ownership' => $transfersOwnership,
                'note' => $note !== '' ? $note : null,
                'created_by' => $creator->id,
                'created_by_staff' => $asStaff,
                'expires_at' => $expiresAt,
                'previous_name' => $passenger->name,
            ]);
        });
    }

    /** ยกเลิกลิงก์ที่ยังไม่มีคนรับ — เจ้าของ คนออกลิงก์ หรือทีมงาน */
    public function cancel(Booking $booking, int $handoverId, User $actor, bool $asStaff = false): SeatHandover
    {
        $handover = SeatHandover::where('booking_id', $booking->id)->whereKey($handoverId)->first();
        if (! $handover) {
            throw new \Exception('ไม่พบลิงก์ส่งต่อที่นั่งนี้');
        }

        $allowed = $asStaff
            || (int) $booking->user_id === (int) $actor->id
            || (int) $handover->created_by === (int) $actor->id;
        if (! $allowed) {
            throw new \Exception('คุณยกเลิกลิงก์นี้ไม่ได้');
        }

        if (! $handover->isPending()) {
            throw new \Exception($handover->status === SeatHandover::STATUS_CLAIMED
                ? 'มีคนรับที่นั่งนี้ไปแล้ว ยกเลิกไม่ได้'
                : 'ลิงก์นี้ถูกยกเลิกไปแล้ว');
        }

        $handover->update([
            'status' => SeatHandover::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
        ]);

        return $handover;
    }

    /* ------------------------------------------------------------------ */
    /*  ฝั่งคนรับ */
    /* ------------------------------------------------------------------ */

    public function findByToken(string $token): SeatHandover
    {
        $handover = SeatHandover::where('token', trim($token))
            ->with(['booking.schedule.trip', 'booking.seats', 'booking.pickupPoint', 'passenger.pickupPoint', 'creator'])
            ->first();

        if (! $handover || ! $handover->booking || ! $handover->passenger
            || (int) $handover->passenger->booking_id !== (int) $handover->booking_id) {
            throw new SeatHandoverNotFound('ลิงก์นี้ใช้ไม่ได้แล้ว — ขอลิงก์ใหม่จากคนที่ส่งให้คุณได้เลย');
        }

        return $handover;
    }

    /**
     * เหตุผลที่ผู้ใช้คนนี้รับที่นั่งไม่ได้ — null คือรับได้
     */
    public function claimBlockedReason(SeatHandover $handover, ?User $recipient): ?string
    {
        if ($handover->status === SeatHandover::STATUS_CLAIMED) {
            return (int) $handover->claimed_by === (int) $recipient?->id
                ? 'คุณรับที่นั่งนี้ไปแล้ว ดูได้ที่การจองของฉัน'
                : 'ที่นั่งนี้มีคนรับไปแล้ว';
        }

        if ($handover->status === SeatHandover::STATUS_CANCELLED) {
            return 'ลิงก์นี้ถูกยกเลิกแล้ว — ขอลิงก์ใหม่จากคนที่ส่งให้คุณได้เลย';
        }

        if ($handover->isExpired()) {
            return 'ลิงก์นี้หมดอายุแล้ว — ขอลิงก์ใหม่จากคนที่ส่งให้คุณได้เลย';
        }

        $booking = $handover->booking;

        if ($reason = $this->blockedReason($booking, (bool) $handover->created_by_staff)) {
            return $reason;
        }

        if ($handover->transfers_ownership && ! $booking->isFullyPaid()) {
            return 'การจองนี้ยังชำระเงินไม่ครบ จึงยังโอนสิทธิ์ดูแลการจองไม่ได้ — แจ้งคนที่ส่งลิงก์ให้คุณ';
        }

        if (! $recipient) {
            return null;
        }

        if ((int) $booking->user_id === (int) $recipient->id) {
            return 'คุณเป็นเจ้าของการจองนี้อยู่แล้ว — ส่งลิงก์นี้ให้คนที่จะไปแทน';
        }

        $alreadyMember = BookingMember::where('booking_id', $booking->id)
            ->where('user_id', $recipient->id)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->exists();
        if ($alreadyMember) {
            return 'คุณอยู่ในการจองนี้อยู่แล้ว ส่งลิงก์นี้ให้คนที่จะไปแทน';
        }

        if ($this->hasSeatInRound($booking, $recipient)) {
            return 'คุณมีที่นั่งในรอบนี้อยู่แล้ว รับเพิ่มอีกที่ไม่ได้';
        }

        // ทริปผู้หญิงล้วนตรวจจากคำนำหน้าที่กรอกในฟอร์ม (ClaimSeatHandoverRequest + claim())
        // ไม่ใช่จากโปรไฟล์ — โปรไฟล์ที่ตั้งคำนำหน้าผิดไว้ต้องไม่ปิดทางคนที่ไปได้จริง
        return null;
    }

    /**
     * หน้าพรีวิวก่อนรับ — ไม่มีราคาและไม่มีข้อมูลส่วนตัวของคนเดิม (คนรับไม่จำเป็น
     * ต้องรู้ว่าใครเคยนั่งตรงนี้ หรือการจองนี้จ่ายไปเท่าไหร่)
     *
     * @return array<string, mixed>
     */
    public function preview(string $token, User $viewer): array
    {
        $handover = $this->findByToken($token);
        $booking = $handover->booking;
        $schedule = $booking->schedule;
        $trip = $schedule?->trip;
        $passenger = $handover->passenger;
        $reason = $this->claimBlockedReason($handover, $viewer);
        $pickup = $passenger->pickupPoint ?? $booking->pickupPoint;

        return [
            'token' => $handover->token,
            'status' => $handover->displayStatus(),
            'claimable' => $reason === null,
            'blocked_reason' => $reason,
            'claimed_by_viewer' => $handover->status === SeatHandover::STATUS_CLAIMED
                && (int) $handover->claimed_by === (int) $viewer->id,
            'booking_ref' => $handover->status === SeatHandover::STATUS_CLAIMED
                && (int) $handover->claimed_by === (int) $viewer->id ? $booking->booking_ref : null,
            'from_name' => $this->fromName($handover),
            'note' => $handover->note,
            'transfers_ownership' => (bool) $handover->transfers_ownership,
            'expires_at' => $handover->expires_at?->toISOString(),
            'trip' => $trip ? [
                'title' => $trip->title,
                'slug' => $trip->slug,
                'location' => $trip->location,
                'cover_image' => $trip->cover_image ? MediaDisk::url($trip->cover_image) : null,
                'duration_days' => $trip->duration_days,
                'is_international' => $trip->isInternational(),
                'is_women_only' => (bool) $trip->is_women_only,
            ] : null,
            'schedule' => $schedule ? [
                'id' => $schedule->id,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'return_date' => $schedule->return_date?->toDateString(),
                'departure_label' => $schedule->departureLabelThai(),
                'early_departure_label' => $schedule->earlyDepartureLabelThai(),
                'is_flight' => $schedule->isFlight(),
            ] : null,
            'seat_label' => $this->seatFor($booking, $passenger)?->seat_id,
            'pickup' => $pickup ? [
                'label' => $pickup->pickup_location ?: $pickup->region_label,
                'time' => $pickup->pickup_time,
                'map_url' => $pickup->map_url,
            ] : ($booking->custom_pickup_label ? [
                'label' => $booking->custom_pickup_label,
                'time' => null,
                'map_url' => null,
            ] : null),
            'traveler_count' => $booking->passengers()->count(),
            // ส่วนแบ่งค่าทริปของที่นั่งนี้ที่ยังไม่มีใครจ่าย — รับแล้วจะกลายเป็นของคนรับ
            'pending_share_amount' => $this->pendingShareAmount($booking, $passenger),
            'terms' => [
                'version' => config('legal.terms_version'),
                'url' => url('/terms'),
            ],
            'prefill' => $this->prefillFrom($viewer),
        ];
    }

    /**
     * คนรับกดรับที่นั่ง — เขียนข้อมูลผู้เดินทางใหม่ทั้งชุด ผูกบัญชีเข้ากับใบจอง และถอดคนเดิม
     *
     * @param  array<string, mixed>  $data  ข้อมูลที่ผ่าน ClaimSeatHandoverRequest แล้ว
     */
    public function claim(string $token, User $recipient, array $data, ?string $channel = null, ?string $ip = null): SeatHandover
    {
        $documentIds = [];
        $departedUserIds = [];
        $newMember = null;

        $handover = DB::transaction(function () use ($token, $recipient, $data, $channel, $ip, &$documentIds, &$departedUserIds, &$newMember) {
            $handover = SeatHandover::where('token', trim($token))->lockForUpdate()->first();
            if (! $handover) {
                throw new SeatHandoverNotFound('ลิงก์นี้ใช้ไม่ได้แล้ว — ขอลิงก์ใหม่จากคนที่ส่งให้คุณได้เลย');
            }

            $booking = Booking::whereKey($handover->booking_id)->lockForUpdate()->first();
            $passenger = BookingPassenger::whereKey($handover->passenger_id)->lockForUpdate()->first();
            if (! $booking || ! $passenger || (int) $passenger->booking_id !== (int) $booking->id) {
                throw new SeatHandoverNotFound('ลิงก์นี้ใช้ไม่ได้แล้ว — ขอลิงก์ใหม่จากคนที่ส่งให้คุณได้เลย');
            }

            $booking->load(['schedule.trip', 'seats', 'passengers']);
            $handover->setRelation('booking', $booking);

            if ($reason = $this->claimBlockedReason($handover, $recipient)) {
                throw new \Exception($reason);
            }

            if ($booking->schedule?->trip?->is_women_only
                && ! in_array(trim((string) ($data['title'] ?? '')), self::WOMEN_ONLY_TITLES, true)) {
                throw new \Exception("ทริปนี้เป็นทริปสำหรับผู้หญิงเท่านั้น กรุณาเลือกคำนำหน้าชื่อเป็น 'นาง' หรือ 'นางสาว'");
            }

            $previousName = $passenger->name;
            $previousOwnerId = (int) $booking->user_id;
            $seat = $this->seatFor($booking, $passenger);

            // ข้อมูลคนเดิมต้องหายทั้งหมด ไม่ใช่แค่ช่องที่คนใหม่กรอก
            $fields = [];
            foreach (self::PERSONAL_FIELDS as $field) {
                $fields[$field] = $data[$field] ?? null;
            }
            $fields['nationality'] = $fields['nationality'] ?: 'TH';
            $fields['halal_food'] = (bool) ($data['halal_food'] ?? false);

            $passenger->forceFill([
                ...$fields,
                'dive_cert_level' => null,
                'cert_number' => null,
                'weight' => null,
                'self_fill_token' => null,
                'self_fill_expires_at' => null,
                'self_filled_at' => now(),
                // บัตรขึ้นรถและลิงก์ของเพื่อนเป็นของคนเดิม — ออกใหม่ให้คนที่รับที่นั่ง
                // ไม่งั้นคนที่ส่งต่อไปแล้วยังถือ QR ที่สแกนขึ้นรถได้อยู่
                'qr_code' => Booking::generateQrCode(),
                'pass_token' => null,
                'checked_in_at' => null,
                'not_going_at' => null,
            ])->save();

            $seat?->update(['passenger_name' => $passenger->name]);

            $documentIds = BookingDocument::where('booking_passenger_id', $passenger->id)->pluck('id')->all();

            // คนเดิมที่ผูกกับที่นั่งนี้ (รับคำเชิญแล้ว หรือคำเชิญที่ยังค้าง) ออกจากใบจอง
            $previousMembers = BookingMember::where('booking_id', $booking->id)
                ->where('passenger_id', $passenger->id)
                ->get();
            $previousMemberUserId = $previousMembers
                ->firstWhere('status', BookingMember::STATUS_ACTIVE)?->user_id;
            foreach ($previousMembers as $member) {
                $member->delete();
            }
            if ($previousMemberUserId) {
                $departedUserIds[] = (int) $previousMemberUserId;
            }

            // แถวเก่าของคนรับในใบเดียวกัน (เคยถูกถอด) ชน unique(booking_id, user_id)
            BookingMember::where('booking_id', $booking->id)
                ->where('user_id', $recipient->id)
                ->delete();

            if ($handover->transfers_ownership) {
                $booking->user_id = $recipient->id;
                $booking->save();
                $departedUserIds[] = $previousOwnerId;
            } else {
                $newMember = BookingMember::create([
                    'booking_id' => $booking->id,
                    'user_id' => $recipient->id,
                    'passenger_id' => $passenger->id,
                    'role' => BookingMember::ROLE_COMPANION,
                    'status' => BookingMember::STATUS_ACTIVE,
                    'invite_label' => $passenger->nickname ?: $passenger->name,
                    'invited_by' => $handover->created_by ?: $booking->user_id,
                    'accepted_at' => now(),
                ]);
            }

            $handover->forceFill([
                'status' => SeatHandover::STATUS_CLAIMED,
                'claimed_by' => $recipient->id,
                'claimed_at' => now(),
                'previous_name' => $previousName,
                'new_name' => $passenger->name,
                'previous_owner_id' => $handover->transfers_ownership ? $previousOwnerId : null,
                'previous_member_user_id' => $previousMemberUserId,
                'terms_version' => $data['terms_version'] ?? config('legal.terms_version'),
                'channel' => $channel,
                'ip' => $ip,
            ])->save();

            return $handover;
        });

        $this->afterClaim($handover->fresh(['booking.schedule.trip', 'booking.seats', 'passenger', 'creator']), $recipient, $newMember, $documentIds, array_values(array_unique($departedUserIds)));

        return $handover->fresh(['booking.schedule.trip', 'passenger']);
    }

    /**
     * ผลข้างเคียงหลัง commit — ทุกอย่างในนี้ best-effort ห้ามทำให้การรับที่นั่งที่
     * บันทึกแล้วกลายเป็น error บนจอคนรับ
     *
     * @param  list<int>  $documentIds
     * @param  list<int>  $departedUserIds
     */
    private function afterClaim(SeatHandover $handover, User $recipient, ?BookingMember $newMember, array $documentIds, array $departedUserIds): void
    {
        $booking = $handover->booking;
        $schedule = $booking->schedule;

        $this->bestEffort('documents', function () use ($documentIds) {
            $service = app(BookingDocumentService::class);
            foreach (BookingDocument::whereKey($documentIds)->get() as $document) {
                $service->delete($document);
            }
        });

        if ($newMember) {
            $this->bestEffort('split share', fn () => app(SplitPaymentService::class)->linkMemberToShare($newMember));
        }

        foreach ($departedUserIds as $userId) {
            if ($userId === (int) $recipient->id) {
                continue;
            }

            $this->bestEffort('live activity', fn () => app(TripActivityService::class)
                ->endForUser($booking, $userId, 'ส่งต่อที่นั่งให้เพื่อนแล้ว'));

            // ยังอยู่ในรอบนี้ด้วยใบจองอื่น/เป็นทีมงาน = ยังแชร์ตำแหน่งต่อได้
            if ($schedule && ! $this->participants->includes($schedule, $userId)) {
                $this->bestEffort('member location', fn () => app(TripMemberLocationService::class)->stop($schedule, $userId));
            }
        }

        AnnounceSeatHandoverJob::dispatch($handover->id);
        SyncTripActivityJob::dispatch($booking->id);

        $this->bestEffort('notifications', fn () => $this->notifyClaimed($handover, $recipient, $departedUserIds));
    }

    private function notifyClaimed(SeatHandover $handover, User $recipient, array $departedUserIds): void
    {
        $booking = $handover->booking;
        $schedule = $booking->schedule;
        $tripTitle = $schedule?->trip?->title ?? 'ทริป';
        $newName = $handover->new_name;
        $previousName = $handover->previous_name;
        $data = ['booking_ref' => $booking->booking_ref, 'route' => 'booking'];
        $notified = [(int) $recipient->id];

        SmartNotification::send(
            $recipient->id,
            'seat_handover_received',
            'รับที่นั่งเรียบร้อย 🎒',
            $handover->transfers_ownership
                ? "ทริป \"{$tripTitle}\" เป็นของคุณแล้ว รวมถึงการดูแลการจองนี้ ดูรายละเอียดได้ที่การจองของฉัน"
                : "คุณได้ที่นั่งในทริป \"{$tripTitle}\" แล้ว เข้าห้องแชทกลุ่มและดูกำหนดการได้ที่การจองของฉัน",
            $data,
        );

        // คนออกลิงก์ (ถ้าไม่ใช่ทีมงาน) — คนที่รอคำตอบอยู่
        $creatorId = $handover->created_by_staff ? null : (int) $handover->created_by;
        if ($creatorId && ! in_array($creatorId, $notified, true)) {
            SmartNotification::send(
                $creatorId,
                'seat_handover_claimed',
                'ส่งต่อที่นั่งเรียบร้อย',
                $handover->transfers_ownership
                    ? "{$newName} รับที่นั่งในทริป \"{$tripTitle}\" แล้ว การจองนี้ย้ายไปอยู่ในบัญชีของเขาแล้วครับ"
                    : "{$newName} รับที่นั่งของ {$previousName} ในทริป \"{$tripTitle}\" แล้วครับ",
                in_array($creatorId, $departedUserIds, true) ? ['route' => 'bookings'] : $data,
            );
            $notified[] = $creatorId;
        }

        // เจ้าของการจองตอนนี้ — กรณีเพื่อนร่วมใบส่งต่อที่นั่งตัวเอง เจ้าของต้องรู้ว่าใครมาแทน
        $ownerId = (int) $booking->user_id;
        if (! in_array($ownerId, $notified, true)) {
            SmartNotification::send(
                $ownerId,
                'seat_handover_claimed',
                'มีการเปลี่ยนตัวผู้เดินทาง',
                "{$newName} มาร่วมทริป \"{$tripTitle}\" แทน {$previousName} แล้วครับ",
                $data,
            );
            $notified[] = $ownerId;
        }

        // คนที่ถูกถอดออก (เจ้าของเดิม/เพื่อนที่ผูกกับที่นั่งนี้) ที่ยังไม่รู้เรื่อง
        foreach ($departedUserIds as $userId) {
            if (in_array($userId, $notified, true)) {
                continue;
            }

            SmartNotification::send(
                $userId,
                'seat_handover_removed',
                'ที่นั่งของคุณถูกส่งต่อแล้ว',
                "ที่นั่งในทริป \"{$tripTitle}\" ถูกส่งต่อให้ {$newName} แล้ว การจองนี้จึงไม่อยู่ในบัญชีของคุณแล้วครับ",
                ['route' => 'bookings'],
            );
            $notified[] = $userId;
        }

        if (! $schedule) {
            return;
        }

        // ทีมงานประจำรอบ + คนขับ + แอดมิน — รายชื่อบนรถ/รายชื่อประกันเปลี่ยนแล้ว
        $seat = $this->seatFor($booking, $handover->passenger);
        $body = "{$tripTitle} รอบ ".ThaiDate::short($schedule->departure_date)
            .": {$previousName} → {$newName}"
            .($seat ? " (ที่นั่ง {$seat->seat_id})" : '')
            ." · {$booking->booking_ref}";

        $crew = $this->participants->staffIds($schedule)
            ->push($this->participants->driverId($schedule))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn ($id) => in_array($id, $notified, true));

        foreach ($crew as $userId) {
            SmartNotification::send(
                $userId,
                'seat_handover_staff',
                'เปลี่ยนตัวผู้เดินทาง',
                $body,
                [
                    'booking_ref' => $booking->booking_ref,
                    'schedule_id' => $schedule->id,
                    'trip_title' => $tripTitle,
                    'route' => 'staff_manifest',
                ],
            );
            $notified[] = $userId;
        }

        // whereHas แทน User::role() — role() โยน exception ถ้าระบบยังไม่มี role ชื่อนั้น
        User::whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'operator']))
            ->whereNotIn('id', $notified)
            ->each(function (User $admin) use ($body, $booking) {
                SmartNotification::send(
                    $admin->id,
                    'seat_handover_staff',
                    'เปลี่ยนตัวผู้เดินทาง — ตรวจรายชื่อประกัน',
                    $body,
                    [
                        'booking_ref' => $booking->booking_ref,
                        'schedule_id' => $booking->schedule_id,
                        'route' => 'admin.bookings',
                    ],
                );
            });
    }

    /* ------------------------------------------------------------------ */
    /*  ตัวช่วย */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function present(SeatHandover $handover, bool $withLink = false, bool $withAudit = false): array
    {
        $open = $handover->isOpen();

        return [
            'id' => $handover->id,
            'passenger_id' => $handover->passenger_id,
            'status' => $handover->displayStatus(),
            'transfers_ownership' => (bool) $handover->transfers_ownership,
            'note' => $handover->note,
            'token' => $withLink && $open ? $handover->token : null,
            'url' => $withLink && $open ? $handover->url() : null,
            'expires_at' => $handover->expires_at?->toISOString(),
            'created_at' => $handover->created_at?->toISOString(),
            'created_by_name' => $handover->created_by_staff
                ? 'ทีมงาน'
                : ($handover->creator?->nickname ?: $handover->creator?->name),
            'created_by_staff' => (bool) $handover->created_by_staff,
            'previous_name' => $handover->previous_name,
            'new_name' => $handover->new_name,
            'claimed_at' => $handover->claimed_at?->toISOString(),
            'cancelled_at' => $handover->cancelled_at?->toISOString(),
            ...($withAudit ? [
                'claimed_by' => $handover->claimer ? [
                    'id' => $handover->claimer->id,
                    'name' => $handover->claimer->name,
                    'phone' => $handover->claimer->phone,
                ] : null,
                'terms_version' => $handover->terms_version,
                'channel' => $handover->channel,
                'ip' => $handover->ip,
            ] : []),
        ];
    }

    /**
     * ที่นั่งของผู้เดินทางคนนี้ — จับคู่ทั้งใบแบบหนึ่งคนหนึ่งที่นั่ง: ชื่อตรงก่อน
     * (ที่นั่งที่ถูกจับไปแล้วใช้ซ้ำไม่ได้ ชื่อซ้ำกันจึงไม่ได้ที่นั่งเดียวกัน) แล้วคนที่
     * ชื่อไม่ตรงค่อยรับที่นั่งที่เหลือตามลำดับ
     *
     * ชื่อบนแถวที่นั่งค้างเป็นชื่อเก่าได้ (/p/ กรอกเอง, รับคำเชิญ, แอดมินแก้ชื่อ
     * ไม่ได้ rename booking_seats) — ของเดิมถอยไปตามลำดับทั้งใบ คนที่ชื่อไม่ตรงจึง
     * ไปได้ที่นั่งของคนอื่น แล้วตอนรับลิงก์ก็เขียนชื่อคนใหม่ทับที่นั่งคนนั้นด้วย
     */
    public function seatFor(Booking $booking, BookingPassenger $passenger): ?BookingSeat
    {
        return $this->seatAssignments($booking)[(int) $passenger->id] ?? null;
    }

    /** @return array<int, BookingSeat> passenger_id => ที่นั่ง */
    private function seatAssignments(Booking $booking): array
    {
        $seats = ($booking->relationLoaded('seats') ? $booking->seats : $booking->seats()->get())
            ->sortBy('id')->values();
        if ($seats->isEmpty()) {
            return [];
        }

        $passengers = ($booking->relationLoaded('passengers') ? $booking->passengers : $booking->passengers()->get())
            ->sortBy('id')->values();

        $assigned = [];
        $taken = [];
        foreach ($passengers as $passenger) {
            $name = trim((string) $passenger->name);
            if ($name === '') {
                continue;
            }
            $seat = $seats->first(fn (BookingSeat $s) => ! isset($taken[$s->id])
                && trim((string) $s->passenger_name) === $name);
            if ($seat) {
                $assigned[(int) $passenger->id] = $seat;
                $taken[$seat->id] = true;
            }
        }

        $freeSeats = $seats->reject(fn (BookingSeat $s) => isset($taken[$s->id]))->values();
        $unmatched = $passengers->reject(fn (BookingPassenger $p) => isset($assigned[(int) $p->id]))->values();
        foreach ($unmatched as $index => $passenger) {
            if ($seat = $freeSeats->get($index)) {
                $assigned[(int) $passenger->id] = $seat;
            }
        }

        return $assigned;
    }

    /**
     * เดาว่าผู้เดินทางคนไหนคือเจ้าของการจองเอง (เบอร์ตรงก่อน แล้วค่อยชื่อตรง) —
     * ใช้แค่ตั้งค่าเริ่มต้นของสวิตช์ "นี่คือที่นั่งของฉัน" ผู้ใช้เปลี่ยนเองได้เสมอ
     */
    private function ownerSeatGuess(Booking $booking): ?int
    {
        $owner = $booking->user;
        if (! $owner) {
            return null;
        }

        $linked = BookingMember::where('booking_id', $booking->id)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->whereNotNull('passenger_id')
            ->pluck('passenger_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $candidates = $booking->passengers->reject(fn ($p) => in_array((int) $p->id, $linked, true));
        $digits = fn ($v) => preg_replace('/\D/', '', (string) $v);
        $ownerPhone = $digits($owner->phone);

        if ($ownerPhone !== '') {
            $byPhone = $candidates->first(fn ($p) => $digits($p->phone) === $ownerPhone);
            if ($byPhone) {
                return (int) $byPhone->id;
            }
        }

        $ownerName = trim((string) $owner->name);
        $byName = $ownerName !== ''
            ? $candidates->first(fn ($p) => trim((string) $p->name) === $ownerName)
            : null;

        return $byName ? (int) $byName->id : null;
    }

    /** @return Collection<int, SeatHandover> */
    private function openHandovers(Booking $booking): Collection
    {
        return SeatHandover::where('booking_id', $booking->id)
            ->where('status', SeatHandover::STATUS_PENDING)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();
    }

    private function hasSeatInRound(Booking $booking, User $user): bool
    {
        $bookingIds = Booking::where('schedule_id', $booking->schedule_id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('id', '!=', $booking->id)
            ->pluck('id');

        if ($bookingIds->isEmpty()) {
            return false;
        }

        return Booking::whereIn('id', $bookingIds)->where('user_id', $user->id)->exists()
            || BookingMember::whereIn('booking_id', $bookingIds)
                ->where('user_id', $user->id)
                ->where('status', BookingMember::STATUS_ACTIVE)
                ->exists();
    }

    private function pendingShareAmount(Booking $booking, BookingPassenger $passenger): ?float
    {
        $amount = BookingSplitShare::where('booking_id', $booking->id)
            ->where('passenger_id', $passenger->id)
            ->where('status', BookingSplitShare::STATUS_PENDING)
            ->sum('amount');

        return $amount > 0 ? round((float) $amount, 2) : null;
    }

    private function fromName(SeatHandover $handover): string
    {
        if ($handover->created_by_staff) {
            return 'ทีมงานลุยเลเขา';
        }

        $creator = $handover->creator;
        $nickname = trim((string) $creator?->nickname);
        if ($nickname !== '') {
            return $nickname;
        }

        $name = trim((string) $creator?->name);

        return $name !== '' ? explode(' ', $name)[0] : 'เพื่อนของคุณ';
    }

    /** @return array<string, mixed> */
    private function prefillFrom(User $user): array
    {
        return [
            'title' => $user->title,
            'name' => $user->name,
            'nickname' => $user->nickname,
            'nationality' => $user->nationality ?: 'TH',
            'id_card' => $user->id_card,
            'birth_date' => $user->birth_date?->toDateString(),
            'phone' => $user->phone,
            'email' => Str::endsWith((string) $user->email, '@social.local') ? null : $user->email,
            'blood_group' => $user->blood_group,
            'allergies' => $user->allergies,
            'health_notes' => $user->health_notes,
            'emergency_contact' => $user->emergency_contact,
            'emergency_phone' => $user->emergency_phone,
            'name_en' => $user->name_en,
            'passport_no' => $user->passport_no,
            'passport_expires_at' => $user->passport_expires_at?->toDateString(),
        ];
    }

    private function generateToken(): string
    {
        do {
            $token = Str::random(32);
        } while (SeatHandover::where('token', $token)->exists());

        return $token;
    }

    private function bestEffort(string $what, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("SeatHandover: {$what} failed", ['message' => $e->getMessage()]);
        }
    }
}
