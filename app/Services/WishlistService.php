<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\TripAlert;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ทริปที่ถูกใจ (หัวใจ) ผูกกับบัญชี — ใช้ร่วมกันทั้งแอปและเว็บ
 *
 * กดหัวใจ = เปิดกระดิ่งแจ้งเตือนทริปนั้นให้ด้วย (เปิดรอบใหม่ + ราคาลด) ถ้ายัง
 * ไม่มีกระดิ่ง กระดิ่งนี้ติดป้าย source=wishlist เอาหัวใจออกแล้วปิดตาม ส่วน
 * กระดิ่งที่ผู้ใช้กดเปิดเอง (manual) ไม่ถูกแตะเลย
 *
 * แจ้ง "ที่นั่งใกล้เต็ม" ไม่เปิดให้ — กดหัวใจไว้เยอะ ๆ จะโดนแจ้งถี่เกิน คนที่อยาก
 * ได้ยังกดกระดิ่งเองได้ที่หน้าทริป
 */
class WishlistService
{
    /** กันรายการโตไม่จำกัด (และกันสคริปต์ยิง merge ใหญ่ ๆ) */
    public const MAX_ITEMS = 300;

    public function __construct(private TripAlertService $alerts) {}

    /**
     * @return array{item: WishlistItem, alert_on: bool}
     */
    public function add(User $user, Trip $trip): array
    {
        $item = DB::transaction(function () use ($user, $trip) {
            $existing = WishlistItem::where('user_id', $user->id)
                ->where('trip_id', $trip->id)
                ->first();
            if ($existing) {
                return $existing;
            }

            if (WishlistItem::where('user_id', $user->id)->count() >= self::MAX_ITEMS) {
                throw new \RuntimeException('รายการทริปที่ถูกใจเต็มแล้ว ลองเอาทริปที่ไม่สนใจแล้วออกก่อน');
            }

            // กดเบิ้ลสองครั้งพร้อมกัน: อีกคำขอสร้างแถวไปก่อน → ได้แถวนั้นคืน ไม่ใช่ 500
            $item = WishlistItem::createOrFirst(['user_id' => $user->id, 'trip_id' => $trip->id]);
            if ($item->wasRecentlyCreated) {
                $this->ensureAlert($user, $trip);
            }

            return $item;
        });

        return ['item' => $item, 'alert_on' => $this->hasAlert($user, $trip)];
    }

    public function remove(User $user, Trip $trip): void
    {
        DB::transaction(function () use ($user, $trip) {
            WishlistItem::where('user_id', $user->id)
                ->where('trip_id', $trip->id)
                ->delete();

            TripAlert::where('user_id', $user->id)
                ->where('trip_id', $trip->id)
                ->where('source', TripAlert::SOURCE_WISHLIST)
                ->delete();
        });
    }

    /**
     * รวมรายการในเครื่อง (ที่กดไว้ตอนยังไม่ล็อกอิน) เข้าบัญชี — เพิ่มอย่างเดียว ไม่ลบ
     * slug ที่ไม่มีแล้ว/ทริปที่ปิดไปแล้วถูกข้าม และไม่เกินเพดาน [MAX_ITEMS]
     *
     * @param  list<string>  $slugs
     */
    public function merge(User $user, array $slugs): void
    {
        $slugs = array_values(array_unique(array_filter(
            array_map(fn ($s) => trim((string) $s), $slugs),
            fn (string $s) => $s !== '',
        )));
        if ($slugs === []) {
            return;
        }

        $trips = Trip::whereIn('slug', $slugs)->where('status', 'active')->get();
        $have = WishlistItem::where('user_id', $user->id)->pluck('trip_id')->all();
        $room = self::MAX_ITEMS - count($have);

        foreach ($trips as $trip) {
            if ($room <= 0) {
                break;
            }
            if (in_array($trip->id, $have, true)) {
                continue;
            }
            WishlistItem::createOrFirst(['user_id' => $user->id, 'trip_id' => $trip->id]);
            $this->ensureAlert($user, $trip);
            $have[] = $trip->id;
            $room--;
        }
    }

    /**
     * รายการสำหรับแสดงผล ใหม่สุดก่อน — เฉพาะทริปที่ยังเปิดอยู่ (ทริปที่ถูกซ่อนไป
     * แถวยังอยู่ กลับมาเปิดเมื่อไหร่ก็โผล่เอง)
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listFor(User $user): Collection
    {
        $items = WishlistItem::where('user_id', $user->id)
            ->whereHas('trip', fn ($q) => $q->where('status', 'active'))
            ->with('trip.schedules')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $alertTripIds = TripAlert::where('user_id', $user->id)
            ->whereIn('trip_id', $items->pluck('trip_id'))
            ->pluck('trip_id')
            ->flip();

        return $items->map(fn (WishlistItem $item) => $this->format(
            $item,
            $alertTripIds->has($item->trip_id),
        ))->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function format(WishlistItem $item, bool $alertOn): array
    {
        $trip = $item->trip;
        // effective_price ของรอบอ่านราคาทริป — ผูกกลับไว้ ไม่งั้นถามทริปซ้ำทุกรอบ
        $trip->schedules->each(fn (TripSchedule $s) => $s->setRelation('trip', $trip));
        $minPrice = $this->alerts->currentMinPrice($trip);
        $nextDeparture = $trip->schedules
            ->filter(fn (TripSchedule $s) => $s->status === 'open'
                && $s->departure_date !== null
                && $s->departure_date->gte(now()->startOfDay()))
            ->sortBy(fn (TripSchedule $s) => $s->departure_date->toDateString())
            ->first();

        return [
            'id' => $trip->id,
            'slug' => $trip->slug,
            'title' => $trip->title,
            'location' => $trip->location,
            'type' => $trip->type,
            'cover_image' => $trip->cover_image,
            'thumbnail_image' => $trip->thumbnail_image,
            'price_per_person' => $trip->price_per_person,
            // ราคาถูกสุดของรอบที่ยังจองได้ — null = ตอนนี้ไม่มีรอบเปิด
            'min_price' => $minPrice,
            'next_departure_date' => $nextDeparture?->departure_date?->toDateString(),
            'alert_on' => $alertOn,
            'added_at' => $item->created_at?->toISOString(),
        ];
    }

    private function hasAlert(User $user, Trip $trip): bool
    {
        return TripAlert::where('user_id', $user->id)
            ->where('trip_id', $trip->id)
            ->exists();
    }

    /** เปิดกระดิ่งให้ถ้ายังไม่มี — กระดิ่งเดิม (เปิดเอง/ปรับเองไว้) ไม่แตะ */
    private function ensureAlert(User $user, Trip $trip): void
    {
        if ($this->hasAlert($user, $trip)) {
            return;
        }

        TripAlert::createOrFirst([
            'user_id' => $user->id,
            'trip_id' => $trip->id,
        ], [
            'source' => TripAlert::SOURCE_WISHLIST,
            'alert_price_drop' => true,
            'alert_new_schedule' => true,
            'alert_low_seats' => false,
            // ตั้งฐานราคาเลย — ราคาที่ลดหลังกดหัวใจจะได้แจ้งตั้งแต่รอบกวาดแรก
            'last_notified_price' => $this->alerts->currentMinPrice($trip),
        ]);
    }
}
