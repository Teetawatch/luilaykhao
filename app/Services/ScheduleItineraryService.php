<?php

namespace App\Services;

use App\Models\ScheduleItineraryItem;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * กำหนดการต่อรอบเดินทาง (itinerary) — แอดมิน/operator สร้าง-แก้-ลบ, สตาฟประจำรอบ
 * อ่านในแอปเพื่อเตรียมตัว/รู้ช่วงเวลา การคุมสิทธิ์อ่านใช้ร่วมกับ ChatService
 * (สตาฟประจำรอบ + ทีมงาน) ส่วนการแก้ไขจำกัดเฉพาะ admin/operator ตามที่ออกแบบไว้
 */
class ScheduleItineraryService
{
    public function __construct(
        private ChatService $chatService,
    ) {}

    /**
     * อ่านได้: สมาชิกห้องแชทของรอบ — ลูกค้าที่จอง active + เพื่อนร่วมทริป +
     * สตาฟประจำรอบ + admin/operator (เปิดให้ลูกค้าดูกำหนดการผ่านปุ่มลัดในแชท
     * เพื่อลดการถามซ้ำ) ส่วนการแก้ไขยังจำกัดเฉพาะ admin/operator ผ่าน canManage
     */
    public function canRead(User $user, TripSchedule $schedule): bool
    {
        return $this->chatService->canAccess($user, $schedule);
    }

    /**
     * เช็คอินจุดกำหนดการ — สตาฟประจำรอบ + admin/operator (ไม่รวมลูกค้า แม้จะ
     * อ่านกำหนดการได้) เพื่อกันลูกค้ากดยืนยันจุดแทนทีมงาน
     */
    public function canCheckIn(User $user, TripSchedule $schedule): bool
    {
        return $this->chatService->canModerate($user, $schedule);
    }

    /**
     * จัดการ (สร้าง/แก้/ลบ) ได้เฉพาะ admin/operator — สตาฟอ่านอย่างเดียว
     */
    public function canManage(User $user, TripSchedule $schedule): bool
    {
        return $user->hasAnyRole(['admin', 'operator']);
    }

    /**
     * @return Collection<int, ScheduleItineraryItem>
     */
    public function list(TripSchedule $schedule): Collection
    {
        return $schedule->itineraryItems()->with('reachedBy:id,name')->get();
    }

    /**
     * เช็คอิน/ยกเลิกเช็คอินจุดกำหนดการ — แชร์ทั้งทีมของรอบนั้น (ใครกดก็เห็นเหมือนกัน)
     */
    public function setReached(ScheduleItineraryItem $item, User $user, bool $reached): ScheduleItineraryItem
    {
        $item->update([
            'reached_at' => $reached ? now() : null,
            'reached_by' => $reached ? $user->id : null,
        ]);

        return $item->fresh('reachedBy');
    }

    public function create(TripSchedule $schedule, User $author, array $data): ScheduleItineraryItem
    {
        return ScheduleItineraryItem::create([
            'schedule_id' => $schedule->id,
            'item_date' => $data['item_date'] ?? null,
            'time' => $data['time'] ?? null,
            'title' => trim($data['title']),
            'detail' => isset($data['detail']) ? trim($data['detail']) : null,
            'link' => isset($data['link']) ? (trim($data['link']) ?: null) : null,
            'sort_order' => $data['sort_order'] ?? 0,
            'created_by' => $author->id,
        ]);
    }

    public function update(ScheduleItineraryItem $item, array $data): ScheduleItineraryItem
    {
        $item->update([
            'item_date' => array_key_exists('item_date', $data) ? $data['item_date'] : $item->item_date,
            'time' => array_key_exists('time', $data) ? $data['time'] : $item->time,
            'title' => isset($data['title']) ? trim($data['title']) : $item->title,
            'detail' => array_key_exists('detail', $data)
                ? ($data['detail'] !== null ? trim($data['detail']) : null)
                : $item->detail,
            'link' => array_key_exists('link', $data)
                ? ($data['link'] !== null ? (trim($data['link']) ?: null) : null)
                : $item->link,
            'sort_order' => $data['sort_order'] ?? $item->sort_order,
        ]);

        return $item->fresh();
    }

    public function delete(ScheduleItineraryItem $item): void
    {
        $item->delete();
    }

    /**
     * จัดลำดับใหม่ตาม array ของ id (ภายในรอบเดียวกัน) — ใช้เมื่อแอดมินลากเรียง
     *
     * @param  array<int, int>  $orderedIds
     */
    public function reorder(TripSchedule $schedule, array $orderedIds): void
    {
        foreach (array_values($orderedIds) as $position => $id) {
            ScheduleItineraryItem::where('schedule_id', $schedule->id)
                ->where('id', $id)
                ->update(['sort_order' => $position]);
        }
    }

    public function present(ScheduleItineraryItem $item): array
    {
        return [
            'id' => $item->id,
            'item_date' => $item->item_date?->toDateString(),
            'time' => $item->time,
            'title' => $item->title,
            'detail' => $item->detail,
            'link' => $item->link,
            'sort_order' => $item->sort_order,
            'reached_at' => $item->reached_at?->toIso8601String(),
            'reached_by_name' => $item->reachedBy?->name,
        ];
    }
}
