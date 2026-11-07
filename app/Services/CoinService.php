<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\CoinTransaction;
use App\Models\Review;
use App\Support\MediaDisk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * กระเป๋า Coin — รีวิวดีได้ Coin, ใช้หักค่าทริปตอนจอง (1 Coin = 1 บาท)
 *
 * `users.coin_balance` คือยอดที่ใช้ได้จริง ทุกการเปลี่ยนยอดผ่าน post() ซึ่งล็อก
 * แถวผู้ใช้และเขียนสมุด coin_transactions คู่กันเสมอ
 *
 * กติการางวัลรีวิว — ได้ครั้งเดียวต่อ "ผู้รีวิว × ใบจอง" (สมุดอ้างอิงใบจอง ไม่ใช่
 * ตัวรีวิว) ลบรีวิวแล้วเขียนใหม่จึงไม่ได้ซ้ำ แก้รีวิวเพิ่มรูป/วิดีโอทีหลังได้ส่วน
 * ที่ยังขาด แต่ลดเนื้อหาลงไม่ถูกหักคืน (ไม่งั้นลบรูป→ใส่ใหม่กลายเป็นปั๊ม Coin
 * เมื่อยอดถูกใช้ไปแล้ว) มีแต่ทีมงานซ่อน/ลบรีวิวเท่านั้นที่ถอนคืน
 */
class CoinService
{
    public function enabled(): bool
    {
        return (bool) config('coins.enabled', true);
    }

    public function balance(int $userId): int
    {
        return (int) DB::table('users')->where('id', $userId)->value('coin_balance');
    }

    /**
     * ความยาวข้อความที่นับรางวัล — ยุบช่องว่างซ้ำแล้วนับเป็น code point
     * (แอปนับแบบเดียวกันด้วย runes ตัวเลขนับถอยหลังบนจอจึงตรงกับที่นี่)
     */
    public static function textLength(?string $text): int
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return mb_strlen($normalized);
    }

    /**
     * กติกาที่หน้าจอต้องรู้ — ส่งให้แอป ไม่ต้องฮาร์ดโค้ดตัวเลขซ้ำ
     *
     * @return array{enabled: bool, min_chars: int, text: int, image: int, video: int, max: int, spend_max_percent: int}
     */
    public function rules(): array
    {
        $text = (int) config('coins.review.text', 4);
        $image = (int) config('coins.review.image', 3);
        $video = (int) config('coins.review.video', 3);

        return [
            'enabled' => $this->enabled(),
            'min_chars' => (int) config('coins.review.min_chars', 100),
            'text' => $text,
            'image' => $image,
            'video' => $video,
            'max' => $text + $image + $video,
            'spend_max_percent' => (int) config('coins.spend.max_percent', 10),
        ];
    }

    /**
     * รีวิวนี้ควรได้กี่ Coin ตามเนื้อหาตอนนี้
     *
     * รีวิวที่แอดมินส่งแทนลูกค้า (reviewer_name) ไม่ได้ — ไม่งั้น Coin เข้าบัญชีแอดมิน
     * รีวิวที่ถูกซ่อนก็ไม่ได้ รูป/วิดีโอนับเฉพาะไฟล์ที่อัปผ่านระบบเราจริง
     *
     * @return array{text: int, image: int, video: int, total: int}
     */
    public function reviewBreakdown(Review $review): array
    {
        $none = ['text' => 0, 'image' => 0, 'video' => 0, 'total' => 0];

        // is_approved เป็น null ได้บนโมเดลที่เพิ่ง create (ค่า default ของคอลัมน์ยังไม่ถูกโหลด)
        if (! $this->enabled() || $review->reviewer_name || $review->is_approved === false) {
            return $none;
        }

        $rules = $this->rules();
        $text = self::textLength($review->comment) >= $rules['min_chars'] ? $rules['text'] : 0;
        $image = $this->hasOwnMedia($review->images) ? $rules['image'] : 0;
        $video = $this->hasOwnMedia($review->videos) ? $rules['video'] : 0;

        return [
            'text' => $text,
            'image' => $image,
            'video' => $video,
            'total' => $text + $image + $video,
        ];
    }

    /**
     * ให้ Coin ส่วนที่รีวิวนี้ยังไม่เคยได้ — เรียกหลังสร้าง/แก้รีวิว และตอนทีมงาน
     * เปิดรีวิวที่ซ่อนไว้กลับมา คืนจำนวนที่เพิ่งได้ (0 = ไม่มีอะไรเพิ่ม)
     */
    public function syncReviewReward(Review $review): int
    {
        if (! $review->user_id || ! $review->booking_id) {
            return 0;
        }

        $target = $this->reviewBreakdown($review)['total'];

        return DB::transaction(function () use ($review, $target) {
            $this->lockUser($review->user_id);
            $already = $this->awardedForBooking($review->user_id, $review->booking_id);
            $delta = max(0, $target - $already);

            if ($delta > 0) {
                $this->post(
                    $review->user_id,
                    $delta,
                    CoinTransaction::TYPE_REVIEW,
                    'รีวิวทริป '.($review->trip?->title ?? $review->booking?->booking_ref ?? ''),
                    $review->booking ?? Booking::find($review->booking_id),
                );
            }

            $review->forceFill(['coins_awarded' => $already + $delta])->saveQuietly();

            return $delta;
        });
    }

    /**
     * ถอน Coin ที่รีวิวของใบจองนี้ได้ไป — ทีมงานซ่อน/ลบรีวิว
     *
     * ถ้าลูกค้าใช้ Coin ไปแล้วจนยอดไม่พอ ถอนเท่าที่มี (ยอดติดลบไม่ได้)
     */
    public function revokeReviewReward(Review $review, string $reason): int
    {
        if (! $review->user_id || ! $review->booking_id) {
            return 0;
        }

        return DB::transaction(function () use ($review, $reason) {
            $balance = $this->lockUser($review->user_id);
            $already = $this->awardedForBooking($review->user_id, $review->booking_id);
            $take = min($already, $balance);

            if ($take > 0) {
                $this->post(
                    $review->user_id,
                    -$take,
                    CoinTransaction::TYPE_REVIEW_REVOKE,
                    $reason,
                    $review->booking ?? Booking::find($review->booking_id),
                );
            }

            if ($review->exists) {
                $review->forceFill(['coins_awarded' => max(0, $already - $take)])->saveQuietly();
            }

            return $take;
        });
    }

    /** Coin สูงสุดที่ใช้กับยอดนี้ได้ — ไม่เกินเพดาน % และไม่เกินยอดที่มี */
    public function maxSpendable(int $userId, float $amount): int
    {
        if (! $this->enabled() || $amount <= 0) {
            return 0;
        }

        $cap = (int) floor($amount * $this->rules()['spend_max_percent'] / 100);

        return max(0, min($cap, $this->balance($userId)));
    }

    /**
     * หัก Coin ตอนสร้างใบจอง — เรียกใน transaction เดียวกับการสร้างใบจอง
     * ยอดไม่พอ (กดจองพร้อมกันสองเครื่อง) โยน \Exception ใบจองจึงไม่เกิด
     */
    public function spendForBooking(Booking $booking, int $coins): void
    {
        if ($coins <= 0) {
            return;
        }

        DB::transaction(function () use ($booking, $coins) {
            $balance = $this->lockUser($booking->user_id);

            if ($balance < $coins) {
                throw new \Exception('Coin ไม่เพียงพอ (มีอยู่ '.number_format($balance).' Coin)');
            }

            $this->post(
                $booking->user_id,
                -$coins,
                CoinTransaction::TYPE_SPEND,
                'ใช้ลดค่าทริป '.$booking->booking_ref,
                $booking,
            );
        });
    }

    /**
     * คืน Coin ของใบจองที่ถูกยกเลิก/คืนเงิน — คืนเข้าบัญชีที่จ่ายไปจริง ไม่ใช่
     * เจ้าของใบตอนนี้ (ใบจองเปลี่ยนมือได้) ทำซ้ำได้ไม่คืนเกิน
     */
    public function restoreForBooking(Booking $booking): int
    {
        if ((int) $booking->coins_used <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($booking) {
            $rows = CoinTransaction::where('reference_type', Booking::class)
                ->where('reference_id', $booking->id)
                ->whereIn('type', [CoinTransaction::TYPE_SPEND, CoinTransaction::TYPE_RESTORE])
                ->lockForUpdate()
                ->get();

            $spend = $rows->firstWhere('type', CoinTransaction::TYPE_SPEND);
            if (! $spend) {
                return 0;
            }

            $owed = -(int) $rows->sum('coins');
            if ($owed <= 0) {
                return 0;
            }

            $this->lockUser($spend->user_id);
            $this->post(
                $spend->user_id,
                $owed,
                CoinTransaction::TYPE_RESTORE,
                'คืน Coin จากการจอง '.$booking->booking_ref.' ที่ถูกยกเลิก',
                $booking,
            );

            return $owed;
        });
    }

    /** Coin สุทธิที่ผู้ใช้คนนี้ได้จากการรีวิวใบจองนี้ (ได้ − ถูกถอน) */
    public function awardedForBooking(int $userId, int $bookingId): int
    {
        return max(0, (int) CoinTransaction::where('user_id', $userId)
            ->where('reference_type', Booking::class)
            ->where('reference_id', $bookingId)
            ->whereIn('type', [CoinTransaction::TYPE_REVIEW, CoinTransaction::TYPE_REVIEW_REVOKE])
            ->sum('coins'));
    }

    /**
     * ไฟล์ที่อัปผ่าน reviews/upload-* จริง — ช่อง images/videos รับสตริงอะไรก็ได้
     * มาตั้งแต่เดิม ถ้าไม่ตรวจ ส่ง URL มั่ว ๆ มาก็ได้ Coin
     */
    private function hasOwnMedia(?array $urls): bool
    {
        $prefix = (string) MediaDisk::url('reviews/');

        foreach ($urls ?? [] as $url) {
            if (is_string($url) && $prefix !== '' && str_starts_with($url, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** ล็อกแถวผู้ใช้ไว้จนจบ transaction แล้วคืนยอดคงเหลือ ณ ตอนล็อก */
    private function lockUser(int $userId): int
    {
        return (int) DB::table('users')->where('id', $userId)->lockForUpdate()->value('coin_balance');
    }

    private function post(int $userId, int $coins, string $type, string $description, ?Model $reference = null): CoinTransaction
    {
        $balance = $this->lockUser($userId) + $coins;

        if ($balance < 0) {
            throw new \Exception('Coin ไม่เพียงพอ');
        }

        DB::table('users')->where('id', $userId)->update(['coin_balance' => $balance]);

        return CoinTransaction::create([
            'user_id' => $userId,
            'type' => $type,
            'coins' => $coins,
            'balance_after' => $balance,
            'description' => mb_substr($description, 0, 255),
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
        ]);
    }
}
