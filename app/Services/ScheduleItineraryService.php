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
     * กำหนดการที่เอาไปแสดง — ถ้ารอบนี้ยังไม่มีกำหนดการเฉพาะรอบ ให้ถอยไปใช้
     * กำหนดการระดับทริป (Trip::itinerary ที่แอดมินเขียนไว้ในหน้าแก้ไขทริป) แทน
     * ลูกค้าจะได้ไม่เจอหน้าว่างทั้งที่ทริปมีแผนเขียนไว้อยู่แล้ว
     *
     * source = 'schedule' (ของรอบนี้ เช็คอินได้) หรือ 'trip' (อ่านอย่างเดียว)
     *
     * @return array{items: array<int, array<string, mixed>>, source: string}
     */
    public function payload(TripSchedule $schedule): array
    {
        $items = $this->list($schedule);

        if ($items->isNotEmpty()) {
            return [
                'items' => $items->map(fn ($i) => $this->present($i))->all(),
                'source' => 'schedule',
            ];
        }

        $fromTrip = $this->fromTrip($schedule);

        return [
            'items' => $fromTrip,
            'source' => $fromTrip === [] ? 'schedule' : 'trip',
        ];
    }

    /**
     * มีกำหนดการให้ดูไหม (ของรอบ หรือของทริปก็นับ) — ใช้ตัดสินปุ่มลัดในแชท
     */
    public function hasItinerary(TripSchedule $schedule): bool
    {
        return $schedule->itineraryItems()->exists() || $this->fromTrip($schedule) !== [];
    }

    /**
     * แปลง Trip::itinerary (โครงสร้าง [{sector, items:[{day,title,description}]}]
     * และรูปแบบเก่า: array แบน / string) ให้เป็นรายการหน้าตาเดียวกับกำหนดการของรอบ
     * เพื่อให้ฝั่งแอปวาดด้วยโค้ดชุดเดิม — id = null เพราะไม่มีแถวจริงให้เช็คอิน
     *
     * @return array<int, array<string, mixed>>
     */
    public function fromTrip(TripSchedule $schedule): array
    {
        $raw = $schedule->trip?->itinerary;

        if (is_string($raw)) {
            $raw = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])));
        }

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $items = [];

        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $this->pushTripItem($items, $schedule, null, ['title' => $entry]);

                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            $children = $this->nestedTripItems($entry);

            if ($children === []) {
                $this->pushTripItem($items, $schedule, null, $entry);

                continue;
            }

            $sector = $this->tripSectorLabel($entry);

            foreach ($children as $child) {
                $this->pushTripItem(
                    $items,
                    $schedule,
                    $sector,
                    is_array($child) ? $child : ['title' => (string) $child],
                );
            }
        }

        return $items;
    }

    /**
     * รายการย่อยของ "ภาค" — รองรับคีย์ที่เคยใช้มาก่อนหน้านี้ด้วย
     *
     * @param  array<string, mixed>  $entry
     * @return array<int, mixed>
     */
    private function nestedTripItems(array $entry): array
    {
        foreach (['items', 'itinerary', 'days', 'program'] as $key) {
            if (isset($entry[$key]) && is_array($entry[$key]) && $entry[$key] !== []) {
                return array_values($entry[$key]);
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function tripSectorLabel(array $entry): ?string
    {
        foreach (['sector', 'sector_name', 'section', 'part', 'region_label', 'region', 'title', 'name'] as $key) {
            $value = isset($entry[$key]) && is_scalar($entry[$key]) ? trim((string) $entry[$key]) : '';
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $data
     */
    private function pushTripItem(array &$items, TripSchedule $schedule, ?string $sector, array $data): void
    {
        $title = $this->firstFilled($data, ['title', 'name', 'activity']);
        $detail = $this->firstFilled($data, ['description', 'desc', 'detail', 'content']);

        if ($title === '' && $detail === '') {
            return;
        }

        if ($title === '') {
            $title = $detail;
            $detail = '';
        }

        [$time, $title] = $this->splitLeadingTime($title);
        $day = $this->tripDayNumber($data);

        $items[] = [
            'id' => null,
            'source' => 'trip',
            'group' => $sector ?? ($day !== null ? "วันที่ {$day}" : 'แผนการเดินทาง'),
            'day' => $day,
            'item_date' => $this->tripItemDate($schedule, $day),
            'time' => $time,
            'title' => $title,
            'detail' => $detail !== '' ? $detail : null,
            'link' => null,
            'sort_order' => count($items),
            'reached_at' => null,
            'reached_by_name' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    private function firstFilled(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = isset($data[$key]) && is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function tripDayNumber(array $data): ?int
    {
        foreach (['day', 'day_number', 'order'] as $key) {
            if (! isset($data[$key]) || ! is_scalar($data[$key])) {
                continue;
            }
            // "1", 1, "วันที่ 2" → 1, 1, 2
            if (preg_match('/\d+/', (string) $data[$key], $m)) {
                $day = (int) $m[0];

                // ตัวแก้ไขทริปเริ่มนับวันที่ 0 ในบางทริป จึงยอมรับ 0 แล้วเลื่อนเป็นวันที่ 1
                return $day >= 1 ? $day : 1;
            }
        }

        return null;
    }

    /**
     * วันที่จริงของ "วันที่ N" ในรอบนี้ — ไม่คำนวณเมื่อเลยวันกลับของรอบ
     * (ทริปเดียวกันอาจมีแผนยาวกว่ารอบนี้) ให้แอปแสดงแค่ "วันที่ N" แทน
     */
    private function tripItemDate(TripSchedule $schedule, ?int $day): ?string
    {
        if ($day === null || $schedule->departure_date === null) {
            return null;
        }

        $date = $schedule->departure_date->copy()->addDays($day - 1);

        if ($schedule->return_date !== null && $date->gt($schedule->return_date)) {
            return null;
        }

        return $date->toDateString();
    }

    /**
     * ดึงเวลานำหน้าหัวข้อออกมาเป็นฟิลด์เวลา ("08:00 ออกเดินทาง" → 08:00 + ออกเดินทาง)
     * เพราะแอดมินมักพิมพ์เวลาไว้ในหัวข้อของกำหนดการระดับทริป
     *
     * @return array{0: ?string, 1: string}
     */
    private function splitLeadingTime(string $title): array
    {
        if (! preg_match('/^(\d{1,2})[:.](\d{2})\s*(?:น\.)?\s*[-–—]?\s*(.+)$/u', $title, $m)) {
            return [null, $title];
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];
        $rest = trim($m[3]);

        if ($hour > 23 || $minute > 59 || $rest === '') {
            return [null, $title];
        }

        return [sprintf('%02d:%02d', $hour, $minute), $rest];
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
            'source' => 'schedule',
            'group' => null,
            'day' => null,
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
