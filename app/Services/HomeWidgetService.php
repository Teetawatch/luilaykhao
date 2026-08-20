<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\TripSchedule;
use App\Support\ThaiDate;
use Carbon\Carbon;

/**
 * "อีก 17 วันไปเขาช้างเผือก" บนหน้าโฮม — ที่เดียวที่นิยามว่าวิดเจ็ตควรขึ้นว่าอะไร
 *
 * [TripActivityService] ดูแลวันเดินทางวันเดียว (การ์ดหน้าจอล็อก) คลาสนี้ดูแล 364 วัน
 * ที่เหลือ: ทริปถัดไปที่จองไว้ กับยอดที่ต้องจ่ายงวดหน้า สองเรื่องที่คนอยากรู้เป็น
 * ระยะ ๆ แต่ไม่คุ้มจะเปิดแอปมาดู
 *
 * ทั้งสองแพลตฟอร์มวาดจาก snapshot ก้อนเดียวกันนี้:
 *
 *   iOS      → App Group UserDefaults → WidgetKit (ios/LiveActivity/TripCountdownWidget.swift)
 *   Android  → SharedPreferences → AppWidgetProvider (TripCountdownWidget.kt)
 *
 * กติกาเดียวกับการ์ดหน้าจอล็อก: ข้อความไทยทุกบรรทัดเขียนที่นี่ ฝั่ง native แค่วาด
 * ยกเว้นข้อเดียวที่ยอมให้ native คิดเอง — ตัวเลข "อีก N วัน" ตอนที่ยังไม่ถึงวัน
 * เดินทาง เพราะวิดเจ็ตต้องนับถอยหลังถูกต้องข้ามคืนโดยไม่มีเน็ตและไม่มีใครเปิดแอป
 * (ดูรายละเอียดของเส้นแบ่งนี้ที่ [tripBlock])
 */
class HomeWidgetService
{
    /**
     * สัญญาข้อมูลของวิดเจ็ต — ขึ้นเลขนี้เมื่อเปลี่ยนโครงจนฝั่ง native อ่านแบบเก่าไม่ได้
     *
     * ฝั่ง native ที่เจอเลขที่ไม่รู้จักจะทิ้ง snapshot แล้ววาดสถานะว่างแทนการเดา —
     * แอปเวอร์ชันเก่าที่ยังไม่อัปเดตจึงไม่แสดงข้อมูลผิด ๆ ค้างบนหน้าโฮม
     */
    public const SNAPSHOT_VERSION = 1;

    private const TIMEZONE = 'Asia/Bangkok';

    public function __construct(private TripActivityService $tripActivity) {}

    /**
     * ทุกอย่างที่วิดเจ็ตต้องรู้ ในก้อนเดียว
     *
     * ตั้งใจให้เรียกได้บ่อย (ทุกครั้งที่แอปกลับมาหน้าจอ) — คิวรีคงที่ ไม่มีการเขียน
     * ฐานข้อมูลแอบแฝง และไม่เคยโยน exception ออกไปหาผู้เรียก
     *
     * @return array<string, mixed>
     */
    public function snapshotFor(int $userId): array
    {
        $booking = $this->nextBooking($userId);

        return [
            'version' => self::SNAPSHOT_VERSION,
            'generated_at' => now(self::TIMEZONE)->toIso8601String(),
            'trip' => $booking ? $this->tripBlock($booking) : null,
            'payment' => $this->paymentBlock($userId),
        ];
    }

    /**
     * ทริปถัดไปที่ยังไม่จบ — รวมรอบที่กำลังเดินทางอยู่ (วันที่ 2 ของทริป 3 วัน)
     *
     * เรียงลำดับใน PHP ไม่ใช่ใน SQL โดยตั้งใจ: คนหนึ่งคนมีทริปข้างหน้าไม่กี่ใบ และ
     * ลำดับที่ถูกต้องคือ `effectiveDepartsAt()` (รอบที่รถออกตีสองของคืนก่อนต้องมาก่อน
     * รอบที่ออกเช้าวันเดียวกัน) ซึ่งเป็นตรรกะใน PHP ไม่ใช่คอลัมน์เดียวใน SQL
     */
    private function nextBooking(int $userId): ?Booking
    {
        $today = $this->todayThai();

        $bookings = Booking::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['confirmed', 'pending'])
            // เทียบกับวันกลับ ไม่ใช่วันออก — ทริปสามวันที่ออกไปเมื่อวานยังต้องแสดงอยู่
            // (`return_date` เป็น NOT NULL ในสคีมา จึงเทียบตรง ๆ ได้)
            ->whereHas('schedule', function ($query) use ($today) {
                $query->where('status', '!=', 'cancelled')
                    ->whereDate('return_date', '>=', $today);
            })
            ->with(['schedule.trip', 'schedule.vehicle', 'pickupPoint'])
            ->get();

        return $bookings
            ->sortBy(fn (Booking $booking) => $booking->schedule?->effectiveDepartsAt()?->getTimestamp() ?? PHP_INT_MAX)
            ->first();
    }

    /**
     * บล็อกทริป — สองโหมดที่ไม่ปนกัน
     *
     * ในช่วง 18 ชม. ก่อนรถออกจนจบทริป [TripActivityService::stateFor] มีคำตอบที่ดี
     * กว่าอยู่แล้ว ("รถถึงใน 8 นาที") วิดเจ็ตจึงยกข้อความชุดนั้นมาใช้ทั้งดุ้น การ์ด
     * หน้าจอล็อกกับวิดเจ็ตหน้าโฮมจะพูดตรงกันเป๊ะเพราะมาจากฟังก์ชันเดียวกัน
     *
     * นอกช่วงนั้นคือการนับถอยหลัง ซึ่งเป็นที่เดียวที่ฝั่ง native คิดเลขเอง:
     * `countdown_days` ที่ส่งไปคือคำตอบ ณ วินาทีที่ดึงข้อมูล แต่วิดเจ็ตจะนับใหม่จาก
     * `departure_date` ทุกครั้งที่วาด — ถ้าไม่ทำอย่างนั้น คนที่ไม่ได้เปิดแอปสามวันจะ
     * เห็น "อีก 17 วัน" ค้างอยู่ทั้งสามวัน ซึ่งแย่กว่าไม่มีวิดเจ็ต ส่วนคำที่ใช้
     * (`อีก N วันออกเดินทาง`) เขียนไว้ทั้งสองที่ให้ตรงกัน และมีเทสต์ทั้งสองฝั่งคุมไว้
     *
     * @return array<string, mixed>
     */
    private function tripBlock(Booking $booking): array
    {
        $schedule = $booking->schedule;
        $departsAt = $schedule->effectiveDepartsAt();
        $days = $this->daysUntilDeparture($schedule);
        $live = $this->tripActivity->stateFor($booking);

        return [
            'booking_ref' => (string) $booking->booking_ref,
            'trip_title' => $schedule->trip?->title ?? 'ทริปของคุณ',
            'departure_date' => $schedule->departure_date?->toDateString(),
            // วันสุดท้ายที่ก้อนนี้ยังจริง — ฝั่ง native เก็บการ์ดออกเองเมื่อเลยวันนี้
            // ไปแล้ว ไม่ต้องรอให้ใครเปิดแอป คนที่ไม่เปิดแอปสองสัปดาห์หลังกลับจาก
            // ทริปจะได้ไม่เห็น "กำลังอยู่ระหว่างทริป" ค้างอยู่บนหน้าโฮม
            'valid_until' => ($schedule->return_date ?? $schedule->departure_date)?->toDateString(),
            'date_label' => ThaiDate::short($schedule->departure_date),
            'depart_time' => $departsAt?->format('H:i'),
            'countdown_days' => $days,
            'headline' => $live['headline'] ?? $this->countdownHeadline($days),
            'detail' => $live['detail'] ?? $this->countdownDetail($booking, $schedule, $departsAt, $days),
            'stage' => $live['stage'] ?? 'countdown',
            'eta_minutes' => $live['eta_minutes'] ?? null,
            'progress' => (float) ($live['progress'] ?? 0.0),
            // ฝั่ง native ใช้ธงนี้ตัดสินว่าจะเชื่อ headline ที่ส่งมา (วันเดินทาง) หรือ
            // จะนับวันเอง (ยังไม่ถึง) — ไม่ใช่ให้มันเดาจาก countdown_days == 0
            'is_live' => $live !== null,
        ];
    }

    /**
     * ยอดที่ต้องจ่ายงวดถัดไป — ใบที่ครบกำหนดก่อนที่สุดใบเดียว
     *
     * ตั้งใจไม่เรียก [OutstandingPaymentService::summarize] ที่มีอยู่แล้ว เพราะมันแถม
     * `pay_url` มาด้วย ซึ่งข้างในเรียก `ensurePaymentToken()` — เขียนฐานข้อมูล
     * endpoint นี้ถูกเรียกทุกครั้งที่แอปกลับมาหน้าจอ จึงต้องอ่านล้วน ๆ
     *
     * @return array<string, mixed>|null
     */
    private function paymentBlock(int $userId): ?array
    {
        $bookings = Booking::query()
            ->where('user_id', $userId)
            ->where('status', 'confirmed')
            ->whereIn('payment_type', ['deposit', 'installment'])
            ->with(['installmentPayments', 'schedule.trip'])
            ->get();

        return $bookings
            ->map(fn (Booking $booking) => $this->outstandingFor($booking))
            ->filter()
            // ใบที่ไม่ได้ตั้งวันครบกำหนดไว้ต้องไปท้ายแถว ไม่ใช่มาแทนใบที่ครบกำหนดพรุ่งนี้
            ->sortBy(fn (array $row) => $row['due_date'] ?? '9999-12-31')
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function outstandingFor(Booking $booking): ?array
    {
        if ($booking->payment_type === 'installment') {
            $next = $booking->installmentPayments
                ->sortBy('installment_no')
                ->first(fn ($installment) => $installment->status !== 'paid');

            if (! $next) {
                return null;
            }

            $amount = (float) $next->amount;
            $due = $next->due_date;
            $label = "งวดที่ {$next->installment_no}/{$booking->installment_count}";
            $slipPending = filled($next->slip_path);
        } elseif ($booking->payment_type === 'deposit') {
            if ($booking->balance_paid_at !== null || (float) $booking->balance_amount <= 0) {
                return null;
            }

            $amount = (float) $booking->balance_amount;
            $due = $booking->balance_due_at;
            $label = 'ยอดส่วนที่เหลือ';
            $slipPending = filled($booking->balance_slip_path);
        } else {
            return null;
        }

        $daysLeft = $this->daysUntil($due);

        return [
            'booking_ref' => (string) $booking->booking_ref,
            'trip_title' => $booking->schedule?->trip?->title ?? 'ทริปของคุณ',
            'label' => $label,
            'amount' => $amount,
            'amount_label' => number_format($amount).' บาท',
            'due_date' => $due?->toDateString(),
            'due_label' => $this->dueLabel($due, $daysLeft, $slipPending),
            'days_left' => $daysLeft,
            'overdue' => ! $slipPending && $daysLeft !== null && $daysLeft < 0,
            'slip_pending' => $slipPending,
        ];
    }

    /**
     * บรรทัดสถานะของยอดค้าง — เรียงตามความเร่งด่วนจากมากไปน้อย
     *
     * สลิปที่แนบแล้วมาก่อนทุกอย่าง คนที่โอนเมื่อคืนแล้วเห็นวิดเจ็ตทวง "เกินกำหนด
     * 2 วัน" จะเข้าใจว่าเงินหาย ซึ่งแย่กว่าไม่บอกอะไรเลย
     */
    private function dueLabel(?Carbon $due, ?int $daysLeft, bool $slipPending): string
    {
        if ($slipPending) {
            return 'แนบสลิปแล้ว รอตรวจสอบ';
        }

        if ($due === null || $daysLeft === null) {
            return 'รอแจ้งวันครบกำหนด';
        }

        if ($daysLeft < 0) {
            return 'เกินกำหนด '.abs($daysLeft).' วัน';
        }

        return match ($daysLeft) {
            0 => 'ครบกำหนดวันนี้',
            1 => 'ครบกำหนดพรุ่งนี้',
            default => 'ครบกำหนด '.ThaiDate::short($due),
        };
    }

    /**
     * ต้องตรงกับที่ฝั่ง native เขียนไว้ — นี่คือค่าตั้งต้นและค่าที่ใช้ตอนวิดเจ็ต
     * อ่าน `departure_date` ไม่สำเร็จ
     */
    private function countdownHeadline(int $days): string
    {
        return match (true) {
            $days < 0 => 'กำลังอยู่ระหว่างทริป',
            $days === 0 => 'วันนี้ออกเดินทาง',
            $days === 1 => 'พรุ่งนี้ออกเดินทาง',
            default => "อีก {$days} วันออกเดินทาง",
        };
    }

    /**
     * บรรทัดรอง — วันที่ · เวลารถออก · จุดรับ เท่าที่รอบนั้นมีข้อมูล
     *
     * ใบที่ยังไม่ยืนยันเอาสถานะขึ้นก่อน เพราะ "จ่ายแล้วหรือยังไม่จ่าย" สำคัญกว่า
     * รายละเอียดจุดรับสำหรับคนที่ยังรอผลตรวจสลิป
     */
    private function countdownDetail(Booking $booking, TripSchedule $schedule, ?Carbon $departsAt, int $days): string
    {
        if ($booking->status === 'pending') {
            return 'รอตรวจสอบการชำระเงิน · '.ThaiDate::short($schedule->departure_date);
        }

        $parts = [];

        // ยังอีกไกล: วันที่มีความหมายกว่าเวลารถออก — ใกล้แล้ว: กลับกัน
        if ($days !== 0) {
            $parts[] = ThaiDate::short($schedule->departure_date);
        }
        if ($departsAt) {
            $parts[] = $departsAt->format('H:i').' น.';
        }

        $pickup = $this->pickupName($booking, $schedule);
        if ($pickup !== null) {
            $parts[] = $pickup;
        }

        return $parts === [] ? 'ทริปของคุณ' : implode(' · ', $parts);
    }

    /**
     * จุดที่ต้องไปยืนรอ — รอบที่บินไปไม่มีจุดรับ มีแต่จุดนัดพบที่สนามบิน
     */
    private function pickupName(Booking $booking, TripSchedule $schedule): ?string
    {
        if ($schedule->isFlight()) {
            return trim((string) $schedule->meeting_point) ?: null;
        }

        return $booking->pickupPoint?->pickup_location
            ?: ($booking->custom_pickup_label ?: null);
    }

    private function daysUntilDeparture(TripSchedule $schedule): int
    {
        return $this->daysUntil($schedule->departure_date) ?? 0;
    }

    /**
     * จำนวนวันเต็มจาก "วันนี้ที่กรุงเทพ" ถึงวันเป้าหมาย (ติดลบ = ผ่านไปแล้ว)
     *
     * เทียบกันแบบวันชนวันเสมอ ไม่ใช่ชั่วโมงชนชั่วโมง — "อีก 1 วัน" ต้องหมายถึง
     * พรุ่งนี้ ไม่ใช่ "อีก 24 ชม." ซึ่งพอเป็นรอบที่รถออกตีสองจะกลายเป็นวันนี้
     */
    private function daysUntil(?Carbon $date): ?int
    {
        if ($date === null) {
            return null;
        }

        return (int) Carbon::parse($this->todayThai())
            ->diffInDays($date->copy()->startOfDay(), false);
    }

    private function todayThai(): string
    {
        return now(self::TIMEZONE)->toDateString();
    }
}
