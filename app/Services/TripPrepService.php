<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\User;

/**
 * "เตรียมตัวก่อนเดินทาง" — สิ่งที่ผู้ดูใบจองนี้ยังต้องทำเองก่อนวันเดินทาง
 *
 * ทุกเรื่องในรายการมีที่ทำอยู่แล้ว (ชำระเงิน แนบเอกสาร กรอกพาสปอร์ต เซ็นแบบฟอร์ม
 * อุทยาน บอกว่าไปครบไหม) แต่กระจายอยู่คนละการ์ดในใบจอง ลูกค้าจึงไม่รู้ว่า "ครบหรือยัง"
 * บริการนี้ตอบคำถามเดียวนั้นโดยอ่านจากบริการเจ้าของเรื่องแต่ละตัว — ห้ามคิดเงื่อนไขซ้ำ
 * ที่นี่ ไม่งั้นรายการกับการ์ดในใบจองจะบอกไม่ตรงกัน
 *
 * ส่งแค่คีย์กับตัวเลข ข้อความอยู่ในแอป (ARB ไทย/อังกฤษ) — แต่ละรายการมีสถานะ
 * `todo` (ต้องทำ) `waiting` (ทำแล้ว รอทีมงาน) หรือ `done` รายการที่ไม่เกี่ยวกับใบนี้
 * (ทริปไม่ขอเอกสาร ไปคนเดียว …) ไม่อยู่ในรายการเลย
 *
 * เช็กลิสต์ของที่ต้องเตรียมเก็บในเครื่องลูกค้า (ChecklistStorage) แอปจึงเติมรายการนั้นเอง
 */
class TripPrepService
{
    public const STATUS_TODO = 'todo';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_DONE = 'done';

    /** ถามว่าไปครบไหมตั้งแต่กี่วันก่อนออก — ตรงกับช่วงที่แอปโชว์การ์ด "ไปครบไหม" */
    public const ATTENDANCE_DAYS_BEFORE = 3;

    public function __construct(
        private BookingDocumentService $documents,
        private TravelDocumentService $travelDocuments,
        private PermitSignatureService $permits,
        private TripAttendanceService $attendance,
    ) {}

    /**
     * @return array{booking_ref: string, open: bool, items: list<array<string, mixed>>, done: int, total: int, todo: int, ready: bool}
     */
    public function forBooking(Booking $booking, User $viewer): array
    {
        $booking->loadMissing(['schedule.trip', 'passengers', 'documents', 'installmentPayments']);

        $isOwner = (int) $booking->user_id === (int) $viewer->id;
        $open = $this->isOpen($booking);

        $items = $open ? array_values(array_filter([
            $isOwner ? $this->payment($booking) : null,
            $this->bookingDocuments($booking, $isOwner ? null : $this->memberPassengerId($booking, $viewer)),
            $isOwner ? $this->passport($booking) : null,
            $this->permit($booking, $viewer),
            $isOwner ? $this->attendanceItem($booking) : null,
        ])) : [];

        $done = count(array_filter($items, fn (array $item) => $item['status'] === self::STATUS_DONE));
        $todo = count(array_filter($items, fn (array $item) => $item['status'] === self::STATUS_TODO));

        return [
            'booking_ref' => $booking->booking_ref,
            // ใบที่ยกเลิก/จบทริปแล้ว/รอเลือกรอบใหม่ ไม่มีอะไรให้เตรียม
            'open' => $open,
            'items' => $items,
            'done' => $done,
            'total' => count($items),
            'todo' => $todo,
            'ready' => $open && $todo === 0,
        ];
    }

    /** ยังมีอะไรให้เตรียมไหม: ใบที่ยังมีผล และยังไม่ถึงวันกลับ (เวลาไทย) */
    private function isOpen(Booking $booking): bool
    {
        if (! in_array($booking->status, ['pending', 'confirmed'], true) || $booking->awaitsNewRound()) {
            return false;
        }

        $schedule = $booking->schedule;
        if (! $schedule || $schedule->status === 'cancelled') {
            return false;
        }

        $end = ($schedule->return_date ?? $schedule->departure_date)?->toDateString();

        return $end !== null && $end >= now('Asia/Bangkok')->toDateString();
    }

    /**
     * ยอดที่ต้องจ่ายตอนนี้ — ลำดับเดียวกับแถบค้างชำระบนหน้าแรกของแอป
     * (ยังไม่จ่าย → ยอดคงเหลือมัดจำ → ยอดเพิ่มเติม → งวดถัดไป)
     *
     * @return array<string, mixed>
     */
    private function payment(Booking $booking): array
    {
        if ($booking->status === 'pending') {
            // ส่งสลิปแล้ว ที่นั่งถืออยู่ รอทีมงานตรวจ — ไม่ต้องชวนให้โอนซ้ำ
            if ($booking->slip_ocr_status !== null) {
                return ['key' => 'payment', 'status' => self::STATUS_WAITING, 'kind' => 'slip_review'];
            }

            $total = (float) $booking->total_amount;

            return $total > 0
                ? ['key' => 'payment', 'status' => self::STATUS_TODO, 'kind' => 'pay', 'amount' => $total]
                : ['key' => 'payment', 'status' => self::STATUS_DONE];
        }

        $balance = (float) $booking->balance_amount;
        if (($booking->payment_type ?? 'full') === 'deposit' && $booking->balance_paid_at === null && $balance > 0) {
            return [
                'key' => 'payment',
                'status' => self::STATUS_TODO,
                'kind' => 'balance',
                'amount' => $balance,
                'due_at' => $booking->balance_due_at?->toISOString(),
            ];
        }

        $extra = $booking->extraDueAmount();
        if ($extra > 0) {
            return ['key' => 'payment', 'status' => self::STATUS_TODO, 'kind' => 'extra', 'amount' => $extra];
        }

        if (($booking->payment_type ?? 'full') === 'installment') {
            // งวดแรกจ่ายตอนจองเสมอ งวดที่จ่ายเองได้เริ่มที่ 2
            $next = $booking->installmentPayments
                ->where('status', '!=', 'paid')
                ->filter(fn ($installment) => (int) $installment->installment_no >= 2)
                ->sortBy('installment_no')
                ->first();

            if ($next && (float) $next->amount > 0) {
                return [
                    'key' => 'payment',
                    'status' => self::STATUS_TODO,
                    'kind' => 'installment',
                    'installment_no' => (int) $next->installment_no,
                    'amount' => (float) $next->amount,
                    'due_at' => $next->due_date?->toDateString(),
                ];
            }
        }

        return ['key' => 'payment', 'status' => self::STATUS_DONE];
    }

    /**
     * เอกสารแนบที่ทริปขอแบบบังคับ — นับเป็นช่อง (คน × เอกสาร) เพื่อนร่วมใบนับเฉพาะของตัวเอง
     * ทริปที่มีแต่เอกสารไม่บังคับไม่อยู่ในรายการ (ไม่แนบก็ไปได้)
     *
     * @return array<string, mixed>|null
     */
    private function bookingDocuments(Booking $booking, ?int $onlyPassengerId): ?array
    {
        $payload = $this->documents->payload($booking);

        $slots = collect($payload['passengers'])
            ->when($onlyPassengerId !== null, fn ($rows) => $rows->where('passenger_id', $onlyPassengerId))
            ->flatMap(fn (array $row) => collect($row['requirements'])->where('required', true));

        if ($slots->isEmpty()) {
            return null;
        }

        $missing = $slots->where('is_missing', true)->count();

        return [
            'key' => 'documents',
            'status' => $missing > 0 ? self::STATUS_TODO : self::STATUS_DONE,
            'missing' => $missing,
            'count' => $slots->count(),
        ];
    }

    /** เพื่อนที่ไม่ได้ถือที่นั่งไหนเลยในใบ (ยังไม่ผูกคน) ไม่มีเอกสารของตัวเองให้แนบ */
    private function memberPassengerId(Booking $booking, User $viewer): int
    {
        return (int) BookingMember::where('booking_id', $booking->id)
            ->where('user_id', $viewer->id)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->value('passenger_id');
    }

    /**
     * พาสปอร์ต/เอกสารเดินทางของทริปต่างประเทศ — เล่มที่จะหมดอายุก่อนเกณฑ์ก็ต้องแก้เหมือนกัน
     *
     * @return array<string, mixed>|null
     */
    private function passport(Booking $booking): ?array
    {
        $summary = $this->travelDocuments->summary($booking);
        if (! $summary['required']) {
            return null;
        }

        $missing = (int) $summary['missing_count'];
        $expiring = (int) $summary['expiring_count'];

        return [
            'key' => 'passport',
            'status' => $missing + $expiring > 0 ? self::STATUS_TODO : self::STATUS_DONE,
            'missing' => $missing,
            'expiring' => $expiring,
        ];
    }

    /**
     * แบบฟอร์มอุทยานที่ต้องเซ็น — นับเฉพาะคนที่ผู้ดูเซ็นให้ได้ (เจ้าของ = ทุกคนที่ไป)
     *
     * @return array<string, mixed>|null
     */
    private function permit(Booking $booking, User $viewer): ?array
    {
        $summary = $this->permits->summary($booking, $viewer);
        if (! $summary || ! $summary['open'] || (int) $summary['total'] === 0) {
            return null;
        }

        $signed = (int) $summary['signed_count'];
        $total = (int) $summary['total'];

        return [
            'key' => 'permit',
            'status' => $signed >= $total ? self::STATUS_DONE : self::STATUS_TODO,
            'signed' => $signed,
            'count' => $total,
            'due_at' => $summary['deadline'],
        ];
    }

    /**
     * "ไปครบไหม" — ใบที่ไปกันตั้งแต่สองคน ช่วงสามวันก่อนรถออก จนถึงตอนรถออก
     *
     * @return array<string, mixed>|null
     */
    private function attendanceItem(Booking $booking): ?array
    {
        if ($booking->passengers->count() < 2 || $booking->checked_in || ! $this->attendance->isOpen($booking)) {
            return null;
        }

        $departure = $booking->schedule?->departure_date?->toDateString();
        $askFrom = now('Asia/Bangkok')->addDays(self::ATTENDANCE_DAYS_BEFORE)->toDateString();
        if ($departure === null || $departure > $askFrom) {
            return null;
        }

        return [
            'key' => 'attendance',
            'status' => $booking->attendance_confirmed_at ? self::STATUS_DONE : self::STATUS_TODO,
            'count' => $booking->passengers->count(),
        ];
    }
}
