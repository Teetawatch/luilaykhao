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

    /** ผ่อนได้มากที่สุดกี่งวด ไม่ว่ารอบจะตั้งไว้เท่าไร. */
    public const MAX_INSTALLMENT_COUNT = 6;

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
     * ผ่อนชำระ: จำนวนงวดที่เลือกได้จริง พร้อมยอดต่องวดของแต่ละตัวเลือก
     *
     * @return array{available: bool, interval_days: int, max_count: int, default_count: int|null, options: list<array{count: int, per_amount: float, last_amount: float}>}
     */
    public static function installment(Booking $booking): array
    {
        $schedule = $booking->schedule;
        $total = self::total($booking);
        $interval = max(1, (int) ($schedule?->installment_interval_days ?: 30));

        if (! $schedule || $booking->is_join_trip || ! $schedule->installment_enabled) {
            return [
                'available' => false,
                'interval_days' => $interval,
                'max_count' => 0,
                'default_count' => null,
                'options' => [],
            ];
        }

        $maxCount = min((int) $schedule->installment_count, self::MAX_INSTALLMENT_COUNT);
        // งวดแรกจ่ายวันนี้ งวดที่ n ต้องครบก่อนวันเดินทาง → (n-1) * interval <= วันที่เหลือ
        $feasibleCount = (int) floor(self::daysUntilDeparture($schedule) / $interval) + 1;
        $maxCount = min($maxCount, max(0, $feasibleCount));

        $options = [];
        for ($count = 2; $count <= $maxCount; $count++) {
            $options[] = ['count' => $count] + self::installmentAmounts($total, $count);
        }

        return [
            'available' => $options !== [],
            'interval_days' => $interval,
            'max_count' => $maxCount >= 2 ? $maxCount : 0,
            'default_count' => $options !== [] ? end($options)['count'] : null,
            'options' => $options,
        ];
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
