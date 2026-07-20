<?php

namespace App\Services;

use App\Models\ScheduleItineraryItem;
use App\Models\TripSchedule;
use Illuminate\Support\Collection;

/**
 * ความคืบหน้าของรอบเดินทาง — สร้างจากกำหนดการที่ทีมงานกดยืนยันว่า "ถึงแล้ว"
 * (ScheduleItineraryItem::reached_at) ไม่ได้ใช้ GPS ของลูกค้า จึงไม่กินแบต
 * และไม่ต้องขอสิทธิ์ตำแหน่งเพิ่ม
 *
 * ใช้ร่วมกันสองที่: หน้าวันเดินทางในแอปลูกค้า และลิงก์ให้ที่บ้านติดตาม
 * ทั้งสองที่เห็น "หมุดกำหนดการ" เหมือนกัน ไม่มีพิกัดสดของใคร
 */
class TripProgressService
{
    /**
     * @return array<string, mixed>
     */
    public function forSchedule(TripSchedule $schedule): array
    {
        $items = ScheduleItineraryItem::where('schedule_id', $schedule->id)
            ->orderBy('item_date')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return [
                'has_itinerary' => false,
                'total' => 0,
                'reached_count' => 0,
                'percent' => 0,
                'current' => null,
                'next' => null,
                'items' => [],
                'last_update_at' => null,
            ];
        }

        $reached = $items->filter(fn (ScheduleItineraryItem $i) => $i->reached_at !== null);

        // จุดปัจจุบัน = จุดสุดท้ายที่ถูกยืนยัน, จุดถัดไป = จุดแรกที่ยังไม่ถูกยืนยัน
        $current = $this->lastReached($reached);
        $next = $items->first(fn (ScheduleItineraryItem $i) => $i->reached_at === null);

        return [
            'has_itinerary' => true,
            'total' => $items->count(),
            'reached_count' => $reached->count(),
            'percent' => (int) round(($reached->count() / $items->count()) * 100),
            'current' => $current ? $this->presentItem($current, true) : null,
            'next' => $next ? $this->presentItem($next, false) : null,
            'items' => $items
                ->map(fn (ScheduleItineraryItem $i) => $this->presentItem(
                    $i,
                    $current !== null && $i->id === $current->id,
                ))
                ->values()
                ->all(),
            'last_update_at' => $current?->reached_at?->toIso8601String(),
        ];
    }

    /**
     * จุดที่ถูกยืนยันล่าสุดตามเวลาที่กด — ไม่ใช่ตามลำดับในกำหนดการ เพราะทีมงาน
     * อาจกดย้อนหลังหรือข้ามจุดได้
     */
    private function lastReached(Collection $reached): ?ScheduleItineraryItem
    {
        return $reached
            ->sortByDesc(fn (ScheduleItineraryItem $i) => $i->reached_at?->timestamp ?? 0)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentItem(ScheduleItineraryItem $item, bool $isCurrent): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'time' => $item->time,
            'item_date' => $item->item_date?->toDateString(),
            'reached' => $item->reached_at !== null,
            'reached_at' => $item->reached_at?->toIso8601String(),
            'is_current' => $isCurrent,
        ];
    }
}
