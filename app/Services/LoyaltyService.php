<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Support\LoyaltyTier;
use Illuminate\Support\Facades\DB;

class LoyaltyService
{
    /**
     * บันทึกการจองที่ยืนยันแล้วเข้าบัญชีสมาชิก — นับเป็น 1 ทริป (ตัวตัดสินระดับ)
     * และให้แต้มตามยอดเงิน floor(total_amount / rate) ค่าเริ่มต้น 100 บาท = 1 แต้ม
     *
     * ทำครั้งเดียวต่อการจอง (idempotent) โดยใช้แถว `earn` ของการจองนั้นเป็นตัวกัน
     * ซ้ำ — การจองยอด 0 บาทหรือทริปแถมจึงยังถูกบันทึกเป็นแถวแต้ม 0 เพราะมันต้อง
     * นับเป็นทริปเหมือนกัน และต้องกันซ้ำได้เหมือนกัน
     */
    public function awardForBooking(Booking $booking): void
    {
        if (! $booking->user_id || ! $booking->exists) {
            return; // Guest booking — no account to credit.
        }

        $rate = max(1, (int) config('loyalty.baht_per_point', 100));
        $basePoints = max(0, (int) floor((float) $booking->total_amount / $rate));

        // ตัวคูณคิดจากระดับ ณ ตอนที่ได้แต้ม — คนที่เพิ่งขึ้นระดับจะได้ตัวคูณใหม่
        // ตั้งแต่ทริปถัดไป ไม่ย้อนหลังให้ทริปที่จ่ายไปแล้ว
        $multiplier = (float) LoyaltyTier::perk(
            LoyaltyAccount::tierForUser($booking->user_id),
            'point_multiplier',
        );

        $points = max($basePoints, (int) floor($basePoints * $multiplier));
        $bonus = $points - $basePoints;

        DB::transaction(function () use ($booking, $points, $bonus) {
            $alreadyEarned = LoyaltyTransaction::where('reference_type', Booking::class)
                ->where('reference_id', $booking->id)
                ->where('type', 'earn')
                ->lockForUpdate()
                ->exists();

            if ($alreadyEarned) {
                return; // This booking was already credited.
            }

            $account = LoyaltyAccount::forUser($booking->user_id);
            $account->points += $points;
            $account->lifetime_points += $points;
            $account->lifetime_trips += 1;
            $account->save();
            $account->updateTier();

            LoyaltyTransaction::create([
                'user_id' => $booking->user_id,
                'type' => 'earn',
                'points' => $points,
                'description' => 'สะสมแต้มจากการจอง '.$booking->booking_ref
                    .($bonus > 0 ? ' (รวมโบนัสระดับสมาชิก +'.$bonus.')' : ''),
                'reference_type' => Booking::class,
                'reference_id' => $booking->id,
                'balance_after' => $account->points,
            ]);
        });
    }

    /**
     * ถอนเครดิตของการจองที่ถูกยกเลิก/คืนเงิน — ทริปที่ไม่ได้ไปต้องไม่นับเป็นระดับ
     *
     * ลบแถว earn ของการจองนั้นทิ้งแล้วบันทึกแถว adjust แทน เพื่อให้ประวัติยัง
     * อ่านรู้เรื่องและเพื่อให้การจองกลับมายืนยันใหม่ได้แต้มใหม่ตามปกติ แต้มคงเหลือ
     * ไม่ติดลบแม้ลูกค้าจะแลกของรางวัลไปแล้ว
     */
    public function reverseForBooking(Booking $booking): void
    {
        if (! $booking->user_id || ! $booking->exists) {
            return;
        }

        DB::transaction(function () use ($booking) {
            $earned = LoyaltyTransaction::where('reference_type', Booking::class)
                ->where('reference_id', $booking->id)
                ->where('type', 'earn')
                ->lockForUpdate()
                ->first();

            if (! $earned) {
                return; // ไม่เคยได้เครดิตจากการจองนี้ ไม่มีอะไรต้องถอน
            }

            $account = LoyaltyAccount::forUser($booking->user_id);
            $points = (int) $earned->points;

            $account->points = max(0, (int) $account->points - $points);
            $account->lifetime_points = max(0, (int) $account->lifetime_points - $points);
            $account->lifetime_trips = max(0, (int) $account->lifetime_trips - 1);
            $account->save();
            $account->updateTier();

            $earned->delete();

            LoyaltyTransaction::create([
                'user_id' => $booking->user_id,
                'type' => 'adjust',
                'points' => -$points,
                'description' => 'ยกเลิกการจอง '.$booking->booking_ref.' — ถอนแต้มและทริปสะสมคืน',
                'reference_type' => Booking::class,
                'reference_id' => $booking->id,
                'balance_after' => $account->points,
            ]);
        });
    }
}
