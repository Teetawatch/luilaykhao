<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\TripSchedule;
use Carbon\CarbonImmutable;

/**
 * ยอดที่ต้อง "โอนตอนนี้" ของการจองหนึ่ง แยกตามรูปแบบการชำระ
 *
 * เดิมเว็บ แอป และหลังบ้านต่างคนต่างคำนวณยอดนี้เอง ตัวเลขจึงเพี้ยนกันได้ และ
 * ลูกค้าที่จองเหมือนกันเป๊ะกลับโอนมาคนละยอด สาเหตุที่เจอจริง:
 *   1. มัดจำแบบยอดคงที่ — หลังบ้านคิด "ต่อคน" (resolveDepositAmount) แต่แอปคิด
 *      "ต่อการจอง" กลุ่ม 4 คนจึงเห็น QR ยอดมัดจำของคนเดียว
 *   2. ส่วนลดมัดจำตามระดับสมาชิก (5/10/15%) หลังบ้านหักให้ แต่ไม่มีฝั่งไหนรู้
 *      ลูกค้าขาประจำจึงโอนเต็มยอดมัดจำ เกินกว่าที่ระบบบันทึกว่ารับไว้
 * ทั้งสองกรณีจบลงที่สลิปยอดไม่ตรง ถูกกันไว้ให้แอดมินตรวจ และเงินไม่ตรงบัญชี
 *
 * ทุกฝั่งต้องอ่านยอดจากที่นี่ที่เดียว — ทั้งตอนแสดง QR และตอน charge()
 */
class PaymentQuote
{
    /** ยอดคงเหลือของการจองแบบมัดจำครบกำหนดก่อนวันเดินทางกี่วัน. */
    public const BALANCE_DUE_LEAD_DAYS = 15;

    /**
     * ผ่อนได้มากที่สุดกี่งวด
     *
     * 3 งวดคือเพดานทางธุรกิจ ไม่ใช่ข้อจำกัดทางเทคนิค — เจ้าของตัดสินใจ 2026-08-28 ว่า
     * งวดยิ่งเยอะลูกค้ายิ่งลืมจ่าย ตามเก็บยากกว่าที่ได้คืนมา (เดิม 6)
     * จำนวนงวดจริงยังน้อยกว่านี้ได้เมื่อจองใกล้วันเดินทาง — ดู maxInstallmentCount()
     */
    public const MAX_INSTALLMENT_COUNT = 3;

    /** งวดสุดท้ายต้องครบกำหนดก่อนวันเดินทางกี่วัน — เท่ากับยอดคงเหลือของมัดจำ. */
    public const INSTALLMENT_LEAD_DAYS = self::BALANCE_DUE_LEAD_DAYS;

    /** ระยะห่างขั้นต่ำระหว่างงวด — ถี่กว่านี้ไม่เรียกว่าผ่อน. */
    public const MIN_INSTALLMENT_GAP_DAYS = 14;

    /**
     * ยอดทุกรูปแบบของการจองนี้ — ก้อนเดียวที่ client เอาไปแสดง/สร้าง QR ได้เลย
     *
     * @return array<string, mixed>
     */
    public static function forBooking(Booking $booking): array
    {
        $total = self::total($booking);

        return [
            'total_amount' => $total,
            'passenger_count' => self::passengerCount($booking),
            'full' => [
                'available' => true,
                'amount' => $total,
            ],
            'deposit' => self::deposit($booking),
            'installment' => self::installment($booking),
            'split' => self::split($booking),
        ];
    }

    /**
     * มัดจำ: ยอดที่ต้องโอนตอนนี้ + ยอดคงเหลือ + กำหนดชำระส่วนที่เหลือ
     *
     * reason บอกสาเหตุที่จ่ายมัดจำไม่ได้ เพื่อให้ charge() ตอบข้อความเดิมได้
     * (not_enabled / not_configured / exceeds_total)
     *
     * @return array{available: bool, reason: string|null, amount: float|null, balance: float|null, percent_of_total: int|null, tier_discount_percent: int, balance_due_at: string|null}
     */
    public static function deposit(Booking $booking): array
    {
        $schedule = $booking->schedule;
        $total = self::total($booking);

        $unavailable = fn (string $reason): array => [
            'available' => false,
            'reason' => $reason,
            'amount' => null,
            'balance' => null,
            'percent_of_total' => null,
            'tier_discount_percent' => 0,
            'balance_due_at' => null,
        ];

        if (! $schedule || $booking->is_join_trip || ! $schedule->deposit_enabled) {
            return $unavailable('not_enabled');
        }

        $amount = $schedule->resolveDepositAmount(
            $total,
            self::passengerCount($booking),
            $booking->user_id,
        );

        if ($amount === null) {
            return $unavailable('not_configured');
        }

        // มัดจำเท่ากับหรือมากกว่ายอดรวม = ไม่มีความหมาย ให้จ่ายเต็มไปเลย
        if ($amount >= $total) {
            return $unavailable('exceeds_total');
        }

        $amount = round((float) $amount, 2);

        return [
            'available' => true,
            'reason' => null,
            'amount' => $amount,
            'balance' => round($total - $amount, 2),
            'percent_of_total' => $total > 0 ? (int) round($amount / $total * 100) : null,
            'tier_discount_percent' => self::tierDepositDiscountPercent($booking->user_id),
            'balance_due_at' => self::balanceDueAt($schedule)?->toISOString(),
        ];
    }

    /**
     * ผ่อนชำระ: คิดให้อัตโนมัติจาก "วันที่จอง → วันเดินทาง" ไม่มีสวิตช์ให้ตั้งรายรอบ
     *
     * เดิมแอดมินต้องเปิดสวิตช์และกรอกจำนวนงวด/ระยะห่างเองทุกรอบ ซึ่งพลาดง่ายสองทาง:
     * ลืมเปิดก็ขายเสียโอกาส เปิดไว้แบบเดิน 30 วันตายตัวก็ได้งวดสุดท้ายไปตกวันขึ้นรถ
     * (ลูกค้าเดินทางทั้งที่ยังค้างเงินอยู่งวดหนึ่ง) และเวลาที่ลูกค้าจองล่วงหน้าไว้
     * ก็เหลือเป็นช่องว่างท้ายแผนที่ไม่ได้ใช้
     *
     * ตอนนี้จึงคิดจากช่วงเวลาที่ผ่อนได้จริง = วันนี้ ถึง (วันเดินทาง - INSTALLMENT_LEAD_DAYS)
     * แล้วหารช่วงนั้นให้เท่าๆ กันตามจำนวนงวด งวดแรกจ่ายวันนี้ งวดสุดท้ายตรงกับวันปิดยอดพอดี
     *
     * @return array{available: bool, auto: bool, interval_days: int, max_count: int, default_count: int|null, lead_days: int, final_due_date: string|null, options: list<array{count: int, per_amount: float, last_amount: float, interval_days: int, due_dates: list<string>}>}
     */
    public static function installment(Booking $booking): array
    {
        $schedule = $booking->schedule;
        $total = self::total($booking);
        $maxCount = $booking->is_join_trip ? 0 : self::maxInstallmentCount($schedule);

        $options = [];
        for ($count = 2; $count <= $maxCount; $count++) {
            $options[] = ['count' => $count]
                + self::installmentAmounts($total, $count)
                + [
                    'interval_days' => self::installmentIntervalDays($schedule, $count),
                    'due_dates' => self::installmentDueDates($schedule, $count),
                ];
        }

        $default = $options !== [] ? $options[count($options) - 1] : null;

        return [
            'available' => $default !== null,
            'auto' => true,
            'interval_days' => $default['interval_days'] ?? self::MIN_INSTALLMENT_GAP_DAYS,
            'max_count' => $default['count'] ?? 0,
            'default_count' => $default['count'] ?? null,
            'lead_days' => self::INSTALLMENT_LEAD_DAYS,
            'final_due_date' => $default ? $default['due_dates'][$default['count'] - 1] : null,
            'options' => $options,
        ];
    }

    /**
     * ช่วงเวลาที่ยังผ่อนได้ (วัน) — วันนี้ถึงวันปิดยอด ไม่ใช่ถึงวันเดินทาง
     */
    public static function installmentWindowDays(?TripSchedule $schedule): int
    {
        if (! $schedule || ! $schedule->departure_date) {
            return 0;
        }

        return max(0, self::daysUntilDeparture($schedule) - self::INSTALLMENT_LEAD_DAYS);
    }

    /** รอบนี้ผ่อนได้มากสุดกี่งวด ณ วันนี้ — 0 เมื่อเวลาเหลือไม่พอ. */
    public static function maxInstallmentCount(?TripSchedule $schedule): int
    {
        $window = self::installmentWindowDays($schedule);

        if ($window < self::MIN_INSTALLMENT_GAP_DAYS) {
            return 0;
        }

        return (int) min(
            self::MAX_INSTALLMENT_COUNT,
            (int) floor($window / self::MIN_INSTALLMENT_GAP_DAYS) + 1,
        );
    }

    /**
     * วันครบกำหนดของทุกงวด — งวดแรกวันนี้ งวดสุดท้ายคือวันปิดยอด ที่เหลือหารเท่าๆ กัน
     *
     * ตัวอย่างนี้ต้องเป็นตัวเดียวกับที่ BookingSettlementService เขียนลงตาราง
     * ไม่งั้นตารางที่ลูกค้าเห็นตอนเลือกจะไม่ตรงกับงวดที่ระบบตั้งให้จริง
     *
     * @return list<string>
     */
    public static function installmentDueDates(?TripSchedule $schedule, int $count): array
    {
        $from = CarbonImmutable::now('Asia/Bangkok')->startOfDay();
        $count = max(1, $count);
        $window = self::installmentWindowDays($schedule);

        $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $offset = $count > 1 ? (int) round($i * $window / ($count - 1)) : 0;
            $dates[] = $from->addDays($offset)->toDateString();
        }

        return $dates;
    }

    /** ระยะห่างเฉลี่ยระหว่างงวด (วัน) — ใช้บอกลูกค้าและเก็บไว้กับการจอง. */
    public static function installmentIntervalDays(?TripSchedule $schedule, int $count): int
    {
        if ($count < 2) {
            return 0;
        }

        return max(1, (int) round(self::installmentWindowDays($schedule) / ($count - 1)));
    }

    /**
     * ยอดต่องวด — งวดสุดท้ายรับเศษที่ปัดทิ้งไป ผลรวมจึงเท่ายอดรวมพอดีเสมอ
     *
     * @return array{per_amount: float, last_amount: float}
     */
    public static function installmentAmounts(float $total, int $count): array
    {
        $count = max(1, $count);
        $per = round($total / $count, 2);

        return [
            'per_amount' => $per,
            'last_amount' => round($total - $per * ($count - 1), 2),
        ];
    }

    /**
     * แบ่งจ่ายกลุ่ม: เจ้าของจ่ายส่วนของตัวเองตอนนี้ ที่เหลือหารให้เพื่อนร่วมทริป
     *
     * @return array{available: bool, owner_share: float|null, friends_count: int, friends_total: float|null}
     */
    public static function split(Booking $booking): array
    {
        $total = self::total($booking);
        $passengerCount = self::passengerCount($booking);

        if ($booking->is_join_trip || $passengerCount < 2) {
            return [
                'available' => false,
                'owner_share' => null,
                'friends_count' => 0,
                'friends_total' => null,
            ];
        }

        $ownerShare = round($total / $passengerCount, 2);

        return [
            'available' => true,
            'owner_share' => $ownerShare,
            'friends_count' => $passengerCount - 1,
            'friends_total' => round($total - $ownerShare, 2),
        ];
    }

    /** กำหนดชำระยอดคงเหลือ = ก่อนวันเดินทาง 15 วัน. */
    public static function balanceDueAt(?TripSchedule $schedule): ?CarbonImmutable
    {
        $departureDate = $schedule?->departure_date;

        return $departureDate
            ? CarbonImmutable::parse($departureDate)->subDays(self::BALANCE_DUE_LEAD_DAYS)->startOfDay()
            : null;
    }

    /** ส่วนลดมัดจำตามระดับสมาชิก (%) — 0 เมื่อยังไม่ถึงระดับที่ได้สิทธิ์. */
    public static function tierDepositDiscountPercent(?int $userId): int
    {
        return (int) LoyaltyTier::perk(
            LoyaltyAccount::tierForUser($userId),
            'deposit_discount_percent',
        );
    }

    private static function total(Booking $booking): float
    {
        return round((float) $booking->total_amount, 2);
    }

    /** จำนวนผู้เดินทางในการจอง — ใช้ relation ที่โหลดมาแล้วก่อน เพื่อไม่ยิงคิวรีซ้ำในลิสต์. */
    private static function passengerCount(Booking $booking): int
    {
        if ($booking->relationLoaded('passengers')) {
            return max(1, $booking->passengers->count());
        }

        return max(1, $booking->passengers()->count());
    }

    /** วันที่เหลือถึงวันเดินทาง (นับตามเวลาไทย ไม่ติดลบ). */
    private static function daysUntilDeparture(TripSchedule $schedule): int
    {
        if (! $schedule->departure_date) {
            return 0;
        }

        $today = CarbonImmutable::now('Asia/Bangkok')->startOfDay();
        $departure = CarbonImmutable::parse($schedule->departure_date)->startOfDay();

        return max(0, $today->diffInDays($departure, false));
    }
}
