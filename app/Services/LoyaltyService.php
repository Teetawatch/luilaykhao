<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Support\LoyaltyTier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * บัญชีแต้มสะสม — แต้มถูกเก็บเป็น "ล็อต"
 *
 * แถว `earn` แต่ละแถวคือแต้มก้อนหนึ่งที่มีวันหมดอายุของตัวเองและมีแต้มคงเหลือของ
 * ตัวเอง (`points_remaining`) การแลกของรางวัลตัดจากล็อตที่ใกล้หมดอายุที่สุดก่อน
 * เราจึงตอบได้ว่า "แต้มของคุณ 40 แต้มจะหมดอายุวันที่ ..." แทนที่จะรู้แค่ยอดรวม
 *
 * `loyalty_accounts.points` คือยอดคงเหลือที่ใช้ได้จริง = ผลรวมของล็อตที่ยังไม่หมดอายุ
 */
class LoyaltyService
{
    /** อายุแต้มนับจากวันที่ได้มา. */
    public const POINTS_VALID_MONTHS = 24;

    /** เตือนล่วงหน้ากี่วันก่อนแต้มก้อนหนึ่งจะหมดอายุ. */
    public const EXPIRY_WARNING_DAYS = 30;

    /** สถานะการจองที่ถือว่า "ได้เดินทางกับเราแล้ว" — นับทริปและให้แต้ม. */
    public const EARNING_STATUSES = ['confirmed', 'completed'];

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
            $account->lifetime_trips += 1;
            $account->save();

            $this->credit(
                $booking->user_id,
                $points,
                'สะสมแต้มจากการจอง '.$booking->booking_ref
                    .($bonus > 0 ? ' (รวมโบนัสระดับสมาชิก +'.$bonus.')' : ''),
                $booking,
            );

            $account->fresh()->updateTier();
        });
    }

    /**
     * เพิ่มแต้มเข้าบัญชีเป็นล็อตใหม่ — ทุกทางที่ให้แต้มต้องผ่านที่นี่ ไม่งั้นแต้มก้อน
     * นั้นจะไม่มีวันหมดอายุและตัดจากมันไม่ได้
     */
    public function credit(int $userId, int $points, string $description, ?Model $reference = null): LoyaltyAccount
    {
        return DB::transaction(function () use ($userId, $points, $description, $reference) {
            $account = LoyaltyAccount::forUser($userId);
            $account->points += $points;
            $account->lifetime_points += $points;
            $account->save();

            LoyaltyTransaction::create([
                'user_id' => $userId,
                'type' => 'earn',
                'points' => $points,
                'points_remaining' => max(0, $points),
                'description' => $description,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->getKey(),
                'balance_after' => $account->points,
                'expires_at' => $points > 0
                    ? now()->addMonths(self::POINTS_VALID_MONTHS)
                    : null,
            ]);

            return $account;
        });
    }

    /**
     * ตัดแต้มออกจากบัญชี (แลกของรางวัล) — ตัดจากล็อตที่ใกล้หมดอายุที่สุดก่อน
     * เพื่อให้ลูกค้าไม่เสียแต้มก้อนที่กำลังจะหมดอายุไปเปล่า ๆ
     *
     * โยน \Exception เมื่อแต้มไม่พอ ให้ตัวเรียกแปลงเป็นข้อความที่เหมาะกับหน้าจอ
     */
    public function spend(int $userId, int $points, string $description, ?Model $reference = null): LoyaltyAccount
    {
        if ($points <= 0) {
            throw new \Exception('จำนวนแต้มที่ใช้ต้องมากกว่า 0');
        }

        return DB::transaction(function () use ($userId, $points, $description, $reference) {
            $account = LoyaltyAccount::where('user_id', $userId)->lockForUpdate()->first()
                ?? LoyaltyAccount::forUser($userId);

            if ((int) $account->points < $points) {
                throw new \Exception('แต้มไม่เพียงพอ (ต้องการ '.$points.' แต้ม)');
            }

            $this->consumeLots($userId, $points);

            $account->points -= $points;
            $account->save();

            LoyaltyTransaction::create([
                'user_id' => $userId,
                'type' => 'redeem',
                'points' => -$points,
                'points_remaining' => 0,
                'description' => $description,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->getKey(),
                'balance_after' => $account->points,
            ]);

            return $account;
        });
    }

    // ── ใช้แต้มลดค่าทริป (เฟส 2) ─────────────────────────────────────────────

    /**
     * กติกาการใช้แต้มลดค่าทริป — ส่งให้แอปคิดตัวเลขเดียวกันได้เอง (loyalty/account)
     *
     * @return array{enabled: bool, baht_per_point: float, min_points: int, max_percent: int}
     */
    public function payRules(): array
    {
        $rules = (array) config('loyalty.pay', []);

        return [
            'enabled' => (bool) ($rules['enabled'] ?? true),
            'baht_per_point' => max(0.01, (float) ($rules['baht_per_point'] ?? 1)),
            'min_points' => max(1, (int) ($rules['min_points'] ?? 10)),
            // ต่ำกว่า 100 เสมอ — ใบจองที่แต้มจ่ายหมดจะไม่มีขั้นชำระเงินให้ยืนยันเลย
            'max_percent' => max(0, min(90, (int) ($rules['max_percent'] ?? 50))),
        ];
    }

    public function pointsToBaht(int $points): float
    {
        return round($points * $this->payRules()['baht_per_point'], 2);
    }

    /**
     * แต้มที่ใช้ได้มากที่สุดกับยอดนี้ — ไม่เกินเพดาน % ไม่เกินที่มี และไม่ถึงขั้นต่ำ = 0
     * แอปคิดสูตรเดียวกัน (pointsToApply) ตัวเลขบนจอจึงตรงกับยอดที่ถูกหักจริง
     */
    public function maxPayablePoints(int $userId, float $amount): int
    {
        $rules = $this->payRules();

        if (! $rules['enabled'] || $amount <= 0 || $rules['max_percent'] <= 0) {
            return 0;
        }

        $capBaht = floor($amount * $rules['max_percent'] / 100);
        $cap = (int) floor($capBaht / $rules['baht_per_point']);
        $balance = (int) (LoyaltyAccount::where('user_id', $userId)->value('points') ?? 0);
        $points = max(0, min($cap, $balance));

        return $points >= $rules['min_points'] ? $points : 0;
    }

    /**
     * ตัดแต้มตอนสร้างใบจอง — เรียกใน transaction เดียวกับการสร้างใบจอง แต้มไม่พอ
     * (กดจองพร้อมกันสองเครื่อง) โยน \Exception ใบจองจึงไม่เกิด
     *
     * ล็อตที่ถูกตัดถูกจดไว้ใน meta พร้อมวันหมดอายุเดิม ไว้คืนกลับตอนยกเลิก
     */
    public function spendForBooking(Booking $booking, int $points): void
    {
        if ($points <= 0 || ! $booking->user_id) {
            return;
        }

        DB::transaction(function () use ($booking, $points) {
            LoyaltyAccount::forUser($booking->user_id);
            $account = LoyaltyAccount::where('user_id', $booking->user_id)->lockForUpdate()->first();

            if ((int) $account->points < $points) {
                throw new \Exception(__('แต้มไม่เพียงพอ (มีอยู่ :points แต้ม)', ['points' => number_format((int) $account->points)]));
            }

            $taken = $this->consumeLots($booking->user_id, $points);
            $untracked = $points - array_sum(array_column($taken, 'points'));
            if ($untracked > 0) {
                $taken[] = ['points' => $untracked, 'expires_at' => null];
            }

            $account->points -= $points;
            $account->save();

            LoyaltyTransaction::create([
                'user_id' => $booking->user_id,
                'type' => 'redeem',
                'points' => -$points,
                'points_remaining' => 0,
                'description' => 'ใช้แต้มลดค่าทริป '.$booking->booking_ref,
                'reference_type' => Booking::class,
                'reference_id' => $booking->id,
                'balance_after' => $account->points,
                'meta' => ['lots' => array_map(fn ($lot) => [
                    'points' => (int) $lot['points'],
                    'expires_at' => $lot['expires_at']?->toISOString(),
                ], $taken)],
            ]);
        });
    }

    /**
     * คืนแต้มที่ใช้ลดค่าทริปของใบจองที่ถูกยกเลิก/คืนเงิน — คืนเต็มจำนวนเข้าบัญชีที่
     * จ่ายไปจริง (ใบจองเปลี่ยนมือได้) เป็นล็อตเดิมพร้อมวันหมดอายุเดิม ล็อตที่หมด
     * อายุไปแล้วหรือเหลือไม่ถึง 30 วันได้ต่อเป็น 30 วันนับจากวันนี้ ให้มีเวลาใช้
     * ทำซ้ำได้ไม่คืนเกิน (ล็อตคืนที่อ้างแถวใช้แต้มเป็นตัวกัน) คืนจำนวนแต้มที่คืน
     */
    public function restoreForBooking(Booking $booking): int
    {
        if ((int) $booking->points_used <= 0 || ! $booking->exists) {
            return 0;
        }

        return DB::transaction(function () use ($booking) {
            $spend = LoyaltyTransaction::where('reference_type', Booking::class)
                ->where('reference_id', $booking->id)
                ->where('type', 'redeem')
                ->lockForUpdate()
                ->first();

            if (! $spend) {
                return 0;
            }

            // ล็อตที่คืนเป็นแถว earn (ใช้/หมดอายุได้เหมือนแต้มทั่วไป) อ้างถึงแถวที่ใช้แต้ม
            // ไม่ใช่ใบจอง — แถว earn ที่อ้างใบจองคือ "แต้มที่ได้จากทริปนี้" ซึ่ง
            // awardForBooking/reverseForBooking ใช้เป็นตัวกันซ้ำอยู่
            $alreadyRestored = LoyaltyTransaction::where('reference_type', LoyaltyTransaction::class)
                ->where('reference_id', $spend->id)
                ->where('type', 'earn')
                ->exists();

            if ($alreadyRestored) {
                return 0;
            }

            $userId = (int) $spend->user_id;
            $owed = -(int) $spend->points;
            $lots = (array) ($spend->meta['lots'] ?? []);
            if ($lots === []) {
                $lots = [['points' => $owed, 'expires_at' => null]];
            }

            $floor = now()->addDays(self::EXPIRY_WARNING_DAYS);
            $account = LoyaltyAccount::forUser($userId);

            foreach ($lots as $lot) {
                $points = (int) ($lot['points'] ?? 0);
                if ($points <= 0) {
                    continue;
                }

                $expiresAt = filled($lot['expires_at'] ?? null)
                    ? Carbon::parse($lot['expires_at'])
                    : now()->addMonths(self::POINTS_VALID_MONTHS);
                if ($expiresAt->lessThan($floor)) {
                    $expiresAt = $floor->copy();
                }

                $account->points += $points;

                LoyaltyTransaction::create([
                    'user_id' => $userId,
                    'type' => 'earn',
                    'points' => $points,
                    'points_remaining' => $points,
                    'description' => 'คืนแต้มจากการจอง '.$booking->booking_ref.' ที่ถูกยกเลิก',
                    'reference_type' => LoyaltyTransaction::class,
                    'reference_id' => $spend->id,
                    'balance_after' => $account->points,
                    'expires_at' => $expiresAt,
                ]);
            }

            $account->save();

            return $owed;
        });
    }

    /**
     * หักแต้มคืน (เช่น ภารกิจที่ใบจองถูกยกเลิก) — หักเท่าที่ยังเหลือ ไม่ติดลบ ไม่โยน
     * ข้อผิดพลาด ต่างจาก spend() ที่ต้องมีแต้มพอ คืนจำนวนที่หักได้จริง
     */
    public function deduct(int $userId, int $points, string $description, ?Model $reference = null): int
    {
        if ($points <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($userId, $points, $description, $reference) {
            LoyaltyAccount::forUser($userId);
            $account = LoyaltyAccount::where('user_id', $userId)->lockForUpdate()->first();
            $take = min($points, max(0, (int) $account->points));

            if ($take > 0) {
                $this->consumeLots($userId, $take);
                $account->points -= $take;
            }
            $account->lifetime_points = max(0, (int) $account->lifetime_points - $points);
            $account->save();

            LoyaltyTransaction::create([
                'user_id' => $userId,
                'type' => 'adjust',
                'points' => -$take,
                'points_remaining' => 0,
                'description' => $description,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->getKey(),
                'balance_after' => $account->points,
            ]);

            return $take;
        });
    }

    /**
     * ถอนเครดิตของการจองที่ถูกยกเลิก/คืนเงิน — ทริปที่ไม่ได้ไปต้องไม่นับเป็นระดับ
     *
     * ลบแถว earn ของการจองนั้นทิ้งแล้วบันทึกแถว adjust แทน เพื่อให้ประวัติยัง
     * อ่านรู้เรื่องและเพื่อให้การจองกลับมายืนยันใหม่ได้แต้มใหม่ตามปกติ หักจากยอด
     * คงเหลือเฉพาะแต้มที่ยังไม่ถูกใช้ไป (แต้มที่แลกของรางวัลไปแล้วเรียกคืนไม่ได้)
     */
    public function reverseForBooking(Booking $booking, ?string $description = null): void
    {
        if (! $booking->exists) {
            return;
        }

        DB::transaction(function () use ($booking, $description) {
            $earned = LoyaltyTransaction::where('reference_type', Booking::class)
                ->where('reference_id', $booking->id)
                ->where('type', 'earn')
                ->lockForUpdate()
                ->first();

            if (! $earned) {
                return; // ไม่เคยได้เครดิตจากการจองนี้ ไม่มีอะไรต้องถอน
            }

            // ถอนคืนจากบัญชีที่ "ได้รับแต้มไปจริง" ไม่ใช่เจ้าของใบจองในตอนนี้ —
            // ใบจองเปลี่ยนมือได้ (แอดมินโอนให้ / ผู้รับกดรับของขวัญ) ถ้าอ่านจาก
            // ใบจองจะไปหักบัญชีคนใหม่ที่ยังไม่เคยได้อะไรเลย
            $creditedUserId = (int) $earned->user_id;
            $account = LoyaltyAccount::forUser($creditedUserId);
            $points = (int) $earned->points;
            $stillUnspent = (int) $earned->points_remaining;

            $account->points = max(0, (int) $account->points - $stillUnspent);
            $account->lifetime_points = max(0, (int) $account->lifetime_points - $points);
            $account->lifetime_trips = max(0, (int) $account->lifetime_trips - 1);
            $account->save();
            $account->updateTier();

            $earned->delete();

            LoyaltyTransaction::create([
                'user_id' => $creditedUserId,
                'type' => 'adjust',
                'points' => -$stillUnspent,
                'points_remaining' => 0,
                'description' => $description
                    ?? 'ยกเลิกการจอง '.$booking->booking_ref.' — ถอนแต้มและทริปสะสมคืน',
                'reference_type' => Booking::class,
                'reference_id' => $booking->id,
                'balance_after' => $account->points,
            ]);
        });
    }

    /**
     * ย้ายเครดิตของการจองไปตามเจ้าของใบจองคนใหม่
     *
     * เครดิตสะสมผูกกับ "ใบจอง" ไม่ใช่ "บัญชีที่กดจองครั้งแรก" ทั้งการโอนใบจอง
     * ฝั่งแอดมิน (จองด้วยบัญชีคนอื่นแล้วย้ายทีหลัง) และการกดรับของขวัญ เปลี่ยนตัว
     * คนที่ได้ไปเที่ยวจริง จำนวนทริปสะสมซึ่งเป็นตัวตัดสินระดับสมาชิกจึงต้องตามไป
     * ด้วย ไม่ใช่ค้างอยู่กับบัญชีเดิม
     *
     * ทำเป็น "ถอนจากบัญชีเดิม แล้วให้ใหม่กับบัญชีปลายทาง" เหมือนเพิ่งยืนยันการจอง
     * แต้มจึงถูกคิดด้วยตัวคูณระดับของเจ้าของใหม่และได้อายุแต้มก้อนใหม่ไปเลย
     * แต้มที่เจ้าของเดิมแลกของรางวัลไปแล้วเรียกคืนไม่ได้ (เหมือนตอนยกเลิกการจอง)
     */
    public function transferForBooking(Booking $booking): void
    {
        if (! $booking->exists) {
            return;
        }

        DB::transaction(function () use ($booking) {
            $this->reverseForBooking(
                $booking,
                'ย้ายการจอง '.$booking->booking_ref.' ไปยังบัญชีอื่น — ถอนแต้มและทริปสะสมคืน',
            );

            // ใบจองที่ยังไม่ยืนยัน/ถูกยกเลิกไม่มีเครดิตให้ย้าย มีแค่ของเก่าที่ต้องถอน
            if (in_array($booking->status, self::EARNING_STATUSES, true)) {
                $this->awardForBooking($booking);
            }
        });
    }

    /**
     * ล้างแต้มที่หมดอายุแล้วออกจากบัญชี — คืนจำนวนแต้มที่ถูกล้างทั้งหมด
     *
     * ล็อตที่ `expires_at` ผ่านไปแล้วและยังเหลือแต้มอยู่ จะถูกตัดเหลือศูนย์พร้อม
     * บันทึกแถว `expire` ไว้ในประวัติให้ลูกค้าเห็นว่าแต้มหายไปเพราะอะไร
     */
    public function expireDuePoints(?Carbon $asOf = null): int
    {
        $asOf ??= now();
        $expiredTotal = 0;

        LoyaltyTransaction::where('type', 'earn')
            ->where('points_remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $asOf)
            ->orderBy('user_id')
            ->chunkById(200, function ($lots) use (&$expiredTotal) {
                foreach ($lots->groupBy('user_id') as $userId => $userLots) {
                    $points = (int) $userLots->sum('points_remaining');

                    if ($points <= 0) {
                        continue;
                    }

                    DB::transaction(function () use ($userId, $userLots, $points, &$expiredTotal) {
                        $account = LoyaltyAccount::forUser((int) $userId);
                        $account->points = max(0, (int) $account->points - $points);
                        $account->save();

                        LoyaltyTransaction::whereKey($userLots->pluck('id'))
                            ->update(['points_remaining' => 0]);

                        LoyaltyTransaction::create([
                            'user_id' => $userId,
                            'type' => 'expire',
                            'points' => -$points,
                            'points_remaining' => 0,
                            'description' => 'แต้มหมดอายุ '.$points.' แต้ม (แต้มมีอายุ '
                                .self::POINTS_VALID_MONTHS.' เดือนนับจากวันที่ได้รับ)',
                            'balance_after' => $account->points,
                        ]);

                        $expiredTotal += $points;
                    });
                }
            });

        return $expiredTotal;
    }

    /**
     * แต้มที่กำลังจะหมดอายุภายใน N วัน พร้อมวันหมดอายุที่ใกล้ที่สุด
     *
     * @return array{points: int, at: ?Carbon}
     */
    public function expiringSoon(int $userId, ?int $withinDays = null): array
    {
        $withinDays ??= self::EXPIRY_WARNING_DAYS;

        $lots = LoyaltyTransaction::where('user_id', $userId)
            ->where('type', 'earn')
            ->where('points_remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays($withinDays))
            ->get(['points_remaining', 'expires_at']);

        return [
            'points' => (int) $lots->sum('points_remaining'),
            'at' => $lots->min('expires_at'),
        ];
    }

    /**
     * แต้มที่ยังใช้ได้ รวมเป็นก้อนตามเดือนที่หมดอายุ (ใกล้สุดก่อน) สำหรับไทม์ไลน์ใน
     * หน้าแต้มสะสม — "เดือน มี.ค. 2570 หมด 40 แต้ม" แต้มยุคก่อนมีล็อต (ยอดในบัญชีเกิน
     * ผลรวมล็อต) ไม่มีวันหมดอายุที่บอกได้ จึงไม่อยู่ในรายการ
     *
     * @return array<int, array{month: string, points: int, expires_at: string}>
     */
    public function expiryTimeline(int $userId, int $limit = 12): array
    {
        return LoyaltyTransaction::where('user_id', $userId)
            ->where('type', 'earn')
            ->where('points_remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get(['points_remaining', 'expires_at'])
            ->groupBy(fn (LoyaltyTransaction $lot) => $lot->expires_at->copy()->timezone('Asia/Bangkok')->format('Y-m'))
            ->map(fn ($lots, $month) => [
                'month' => (string) $month,
                'points' => (int) $lots->sum('points_remaining'),
                'expires_at' => $lots->min('expires_at')->toISOString(),
            ])
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * ย้ายแต้มจากบัญชีหนึ่งไปอีกบัญชี (โอนแต้มให้เพื่อน) — ทั้งสองฝั่งในธุรกรรมเดียว
     *
     * แต้มที่ย้ายไป "พกวันหมดอายุเดิมไปด้วย": ล็อตที่ถูกตัดจากผู้โอนกลายเป็นล็อต
     * ใหม่ของผู้รับที่หมดอายุวันเดียวกัน ไม่งั้นการโอนไปกลับระหว่างสองบัญชีจะต่ออายุ
     * แต้มได้ไม่รู้จบ แต้มยุคก่อนมีล็อต (ยอดในบัญชีเกินผลรวมล็อต) ได้อายุเต็มนับจากวันนี้
     *
     * ผู้รับไม่ได้ lifetime_points เพิ่ม — ตัวเลขนั้นคือแต้มที่ "ได้จากการเดินทาง"
     */
    public function transfer(int $fromUserId, int $toUserId, int $points, Model $reference, string $senderDescription, string $recipientDescription): void
    {
        if ($points <= 0) {
            throw new \Exception('จำนวนแต้มที่โอนต้องมากกว่า 0');
        }

        if ($fromUserId === $toUserId) {
            throw new \Exception('โอนแต้มให้ตัวเองไม่ได้');
        }

        DB::transaction(function () use ($fromUserId, $toUserId, $points, $reference, $senderDescription, $recipientDescription) {
            LoyaltyAccount::forUser($fromUserId);
            LoyaltyAccount::forUser($toUserId);

            // ล็อกสองบัญชีตามลำดับ id เสมอ — สองคนโอนหากันพร้อมกันจะได้ไม่ deadlock
            $accounts = LoyaltyAccount::whereIn('user_id', [$fromUserId, $toUserId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('user_id');

            $sender = $accounts[$fromUserId];
            $recipient = $accounts[$toUserId];

            if ((int) $sender->points < $points) {
                throw new \Exception('แต้มไม่เพียงพอ (มีอยู่ '.number_format((int) $sender->points).' แต้ม)');
            }

            $taken = $this->consumeLots($fromUserId, $points);

            $sender->points -= $points;
            $sender->save();

            LoyaltyTransaction::create([
                'user_id' => $fromUserId,
                'type' => 'transfer',
                'points' => -$points,
                'points_remaining' => 0,
                'description' => $senderDescription,
                'reference_type' => $reference::class,
                'reference_id' => $reference->getKey(),
                'balance_after' => $sender->points,
            ]);

            $untracked = $points - array_sum(array_column($taken, 'points'));
            if ($untracked > 0) {
                $taken[] = ['points' => $untracked, 'expires_at' => now()->addMonths(self::POINTS_VALID_MONTHS)];
            }

            // รวมล็อตที่หมดอายุวันเดียวกันเป็นก้อนเดียว ประวัติของผู้รับจะได้ไม่ยาวเกินจำเป็น
            $byExpiry = [];
            foreach ($taken as $lot) {
                $key = $lot['expires_at']?->toDateTimeString() ?? 'never';
                $byExpiry[$key] ??= ['points' => 0, 'expires_at' => $lot['expires_at']];
                $byExpiry[$key]['points'] += $lot['points'];
            }

            foreach ($byExpiry as $lot) {
                $recipient->points += $lot['points'];

                LoyaltyTransaction::create([
                    'user_id' => $toUserId,
                    'type' => 'earn',
                    'points' => $lot['points'],
                    'points_remaining' => $lot['points'],
                    'description' => $recipientDescription,
                    'reference_type' => $reference::class,
                    'reference_id' => $reference->getKey(),
                    'balance_after' => $recipient->points,
                    'expires_at' => $lot['expires_at'],
                ]);
            }

            $recipient->save();
        });
    }

    /**
     * ตัดแต้มออกจากล็อตที่ใกล้หมดอายุที่สุดก่อน (ล็อตไม่มีวันหมดอายุถูกตัดท้ายสุด)
     *
     * @return array<int, array{points: int, expires_at: ?Carbon}> ส่วนที่ตัดได้จริงจากแต่ละล็อต
     */
    private function consumeLots(int $userId, int $points): array
    {
        $remaining = $points;
        $taken = [];

        $lots = LoyaltyTransaction::where('user_id', $userId)
            ->where('type', 'earn')
            ->where('points_remaining', '>', 0)
            ->orderByRaw('expires_at is null')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((int) $lot->points_remaining, $remaining);
            $lot->points_remaining = (int) $lot->points_remaining - $take;
            $lot->save();
            $remaining -= $take;
            $taken[] = ['points' => $take, 'expires_at' => $lot->expires_at];
        }

        // แต้มในบัญชีมากกว่าผลรวมของล็อต (ข้อมูลยุคก่อนมีระบบล็อตที่ยังไม่ได้เกลี่ย)
        // ไม่ถือเป็นข้อผิดพลาด — ยอดคงเหลือในบัญชีเป็นตัวตัดสินว่าแลกได้หรือไม่
        return $taken;
    }
}
