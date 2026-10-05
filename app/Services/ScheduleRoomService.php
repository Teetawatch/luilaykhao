<?php

namespace App\Services;

use App\Jobs\SendChatPushJob;
use App\Models\ScheduleRoom;
use App\Models\ScheduleRoomGuest;
use App\Models\TripSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * จัดห้องพักของรอบ — ใครนอนห้องไหนกับใคร
 *
 * ถึงที่พักแล้ววุ่นทุกครั้ง: ทีมงานจัดไว้ก่อน (มีปุ่มจัดอัตโนมัติให้คนในใบจอง
 * เดียวกันอยู่ด้วยกัน และไม่จับคนแปลกหน้าต่างเพศนอนห้องเดียวกัน) แล้วประกาศ —
 * ลูกทริปเห็นห้องของตัวเองในแอป ส่วนห้องรวมได้รายชื่อทุกห้องไว้ดู
 *
 * ผู้พักอิงผู้โดยสาร (TripRosterService) และแยกตาม stay_label เผื่อทริปที่ค้าง
 * หลายที่ — ในที่พักเดียวกัน หนึ่งคนอยู่ได้ห้องเดียว
 */
class ScheduleRoomService
{
    public function __construct(
        private TripRosterService $roster,
        private ChatService $chatService,
        private FcmService $fcm,
    ) {}

    /**
     * ห้องทั้งหมดของรอบ + ห้องของฉัน (+ คนที่ยังไม่มีห้อง สำหรับทีมงาน)
     *
     * @return array<string, mixed>
     */
    public function present(TripSchedule $schedule, int $viewerId, bool $staffView): array
    {
        $roster = $this->roster->passengers($schedule)->keyBy('passenger_id');
        $rooms = $this->rooms($schedule);
        $mine = $roster->where('user_id', $viewerId)->pluck('passenger_id');

        // ชื่อจริงเต็มเฉพาะทีมงาน — ไว้ส่งรายชื่อเข้าพักให้ที่พัก ห้องรวมเห็นแค่ชื่อเล่น
        $presentRoom = fn (ScheduleRoom $room) => [
            'id' => $room->id,
            'stay_label' => $room->stay_label,
            'name' => $room->name,
            'note' => $room->note,
            'guests' => $room->guests
                ->filter(fn ($g) => $roster->has($g->passenger_id))
                ->map(fn ($g) => [
                    'passenger_id' => (int) $g->passenger_id,
                    'name' => $roster[$g->passenger_id]['name'],
                    'booking_id' => $roster[$g->passenger_id]['booking_id'],
                    'is_mine' => $mine->contains((int) $g->passenger_id),
                    ...($staffView ? ['full_name' => $roster[$g->passenger_id]['full_name']] : []),
                ])->values()->all(),
        ];

        $payload = [
            'stays' => $rooms->pluck('stay_label')->unique()->values()->all(),
            'rooms' => $rooms->map($presentRoom)->values()->all(),
            // ห้องที่มีคนที่ฉันดูแลอยู่ — การ์ด "ห้องของคุณ" บนสุดของหน้า
            'my_room_ids' => $rooms
                ->filter(fn ($r) => $r->guests->pluck('passenger_id')->map(fn ($id) => (int) $id)->intersect($mine)->isNotEmpty())
                ->pluck('id')->values()->all(),
        ];

        if ($staffView) {
            // คนที่ยังไม่มีห้อง แยกตามที่พัก (ยังไม่มีห้องเลย = ที่พักเดียวไม่มีชื่อ)
            $stays = $rooms->isEmpty() ? collect([null]) : $rooms->pluck('stay_label')->unique()->values();
            $payload['unassigned'] = $stays
                ->map(function ($stay) use ($rooms, $roster) {
                    $assigned = $rooms->where('stay_label', $stay)
                        ->flatMap(fn ($r) => $r->guests->pluck('passenger_id'))
                        ->map(fn ($id) => (int) $id);

                    return [
                        'stay_label' => $stay,
                        'passengers' => $roster->reject(fn ($p) => $assigned->contains($p['passenger_id']))
                            ->map(fn ($p) => [
                                'passenger_id' => $p['passenger_id'],
                                'name' => $p['name'],
                                'full_name' => $p['full_name'],
                                'booking_id' => $p['booking_id'],
                                'gender' => $this->roster->genderOf($p['title']),
                            ])->values()->all(),
                    ];
                })
                ->values()->all();
            $payload['total_passengers'] = $roster->count();
        }

        return $payload;
    }

    public function hasRooms(TripSchedule $schedule): bool
    {
        return ScheduleRoom::where('schedule_id', $schedule->id)->exists();
    }

    public function create(TripSchedule $schedule, string $name, ?string $stayLabel, ?string $note): ScheduleRoom
    {
        return ScheduleRoom::create([
            'schedule_id' => $schedule->id,
            'stay_label' => $this->stay($stayLabel),
            'name' => $name,
            'note' => $note ?: null,
            'sort_order' => (int) ScheduleRoom::where('schedule_id', $schedule->id)->max('sort_order') + 1,
        ]);
    }

    public function update(ScheduleRoom $room, string $name, ?string $note): ScheduleRoom
    {
        $room->update(['name' => $name, 'note' => $note ?: null]);

        return $room;
    }

    /**
     * กำหนดผู้พักของห้อง — คนที่ย้ายมาจะออกจากห้องเดิมในที่พักเดียวกันให้เอง
     *
     * @param  array<int, int>  $passengerIds
     */
    public function setGuests(ScheduleRoom $room, array $passengerIds): void
    {
        $valid = $this->roster->passengers($room->schedule)->pluck('passenger_id');
        $ids = collect($passengerIds)->map(fn ($id) => (int) $id)->unique()
            ->filter(fn ($id) => $valid->contains($id))
            ->values();

        if ($ids->count() > ScheduleRoom::MAX_GUESTS) {
            throw new \Exception('ห้องหนึ่งใส่ได้ไม่เกิน '.ScheduleRoom::MAX_GUESTS.' คน');
        }

        DB::transaction(function () use ($room, $ids) {
            ScheduleRoomGuest::whereIn('passenger_id', $ids)
                ->where('room_id', '!=', $room->id)
                ->whereIn('room_id', $this->sameStay($room->schedule_id, $room->stay_label)->select('id'))
                ->delete();

            ScheduleRoomGuest::where('room_id', $room->id)->whereNotIn('passenger_id', $ids)->delete();

            foreach ($ids as $id) {
                ScheduleRoomGuest::firstOrCreate(['room_id' => $room->id, 'passenger_id' => $id]);
            }
        });
    }

    /**
     * จัดคนที่ยังไม่มีห้องให้อัตโนมัติ
     *
     * - คนในใบจองเดียวกันอยู่ห้องเดียวกัน (กลุ่มใหญ่กว่าห้องก็แบ่งเป็นหลายห้องติดกัน)
     * - คนมาคนเดียวจับคู่กันเฉพาะเพศเดียวกัน (ดูจากคำนำหน้าชื่อ) — ไม่รู้เพศก็จับกันเอง
     * - ห้องที่มีที่ว่างอยู่แล้วไม่ถูกแตะ ทีมงานปรับเองได้ทีหลัง
     *
     * @return int จำนวนห้องที่สร้าง
     */
    public function autoAssign(TripSchedule $schedule, ?string $stayLabel, int $roomSize, string $prefix): int
    {
        $stayLabel = $this->stay($stayLabel);
        $roomSize = min(max($roomSize, 1), ScheduleRoom::MAX_GUESTS);

        $assigned = ScheduleRoomGuest::whereIn('room_id', $this->sameStay($schedule->id, $stayLabel)->select('id'))
            ->pluck('passenger_id')
            ->map(fn ($id) => (int) $id);

        $pending = $this->roster->passengers($schedule)
            ->reject(fn ($p) => $assigned->contains($p['passenger_id']))
            ->values();

        if ($pending->isEmpty()) {
            return 0;
        }

        /** @var array<int, array<int, int>> $plan รายชื่อผู้พักของแต่ละห้องใหม่ */
        $plan = [];
        $singles = [];

        foreach ($pending->groupBy('booking_id') as $group) {
            if ($group->count() === 1) {
                $p = $group->first();
                $singles[$this->roster->genderOf($p['title'])][] = $p['passenger_id'];

                continue;
            }
            foreach ($group->pluck('passenger_id')->chunk($roomSize) as $chunk) {
                $plan[] = $chunk->values()->all();
            }
        }

        foreach (['female', 'male', 'unknown'] as $gender) {
            foreach (array_chunk($singles[$gender] ?? [], $roomSize) as $chunk) {
                $plan[] = $chunk;
            }
        }

        $existingNames = $this->sameStay($schedule->id, $stayLabel)->pluck('name');
        $number = 1;

        DB::transaction(function () use ($plan, $schedule, $stayLabel, $prefix, $existingNames, &$number) {
            foreach ($plan as $guestIds) {
                while ($existingNames->contains("{$prefix} {$number}")) {
                    $number++;
                }
                $room = $this->create($schedule, "{$prefix} {$number}", $stayLabel, null);
                $number++;

                foreach ($guestIds as $id) {
                    ScheduleRoomGuest::create(['room_id' => $room->id, 'passenger_id' => $id]);
                }
            }
        });

        return count($plan);
    }

    /**
     * ประกาศห้องพักเข้าห้องแชท + บอกแต่ละคนว่าอยู่ห้องไหนกับใคร
     *
     * @return int จำนวนห้องที่ประกาศ
     */
    public function announce(TripSchedule $schedule, ?string $stayLabel): int
    {
        $stayLabel = $this->stay($stayLabel);
        $roster = $this->roster->passengers($schedule)->keyBy('passenger_id');
        $rooms = $this->rooms($schedule)
            ->filter(fn ($r) => $r->stay_label === $stayLabel)
            ->filter(fn ($r) => $r->guests->isNotEmpty())
            ->values();

        if ($rooms->isEmpty()) {
            throw new \Exception('ยังไม่มีห้องที่จัดคนไว้');
        }

        $names = fn (ScheduleRoom $r) => $r->guests
            ->filter(fn ($g) => $roster->has($g->passenger_id))
            ->map(fn ($g) => $roster[$g->passenger_id]['name']);

        $lines = $rooms->map(fn ($r) => "• {$r->name}: ".$names($r)->join(', ')
            .($r->note ? " ({$r->note})" : ''));

        $this->chatService->postSystem(
            $schedule,
            '🛏️ ห้องพัก'.($stayLabel ? " — {$stayLabel}" : '')."\n".$lines->join("\n")
                ."\nดูห้องของตัวเองได้ในแอป เมนู + › ห้องพัก",
        );

        $tripTitle = $schedule->trip?->title ?? 'ทริปของคุณ';

        foreach ($rooms as $room) {
            $guestNames = $names($room);
            $userIds = $room->guests
                ->map(fn ($g) => $roster[$g->passenger_id]['user_id'] ?? null)
                ->filter()
                ->unique();

            foreach ($userIds as $userId) {
                $mineNames = $room->guests
                    ->filter(fn ($g) => ($roster[$g->passenger_id]['user_id'] ?? null) === $userId)
                    ->map(fn ($g) => $roster[$g->passenger_id]['name']);
                $others = $guestNames->diff($mineNames)->values();

                $this->send((int) $userId,
                    "🛏️ ห้องพักของคุณ: {$room->name}",
                    ($others->isEmpty() ? 'พักห้องนี้' : 'พักกับ '.$others->join(', '))
                        .($room->note ? " · {$room->note}" : '')." · {$tripTitle}",
                    $schedule->id,
                );
            }
        }

        return $rooms->count();
    }

    /**
     * ห้องทั้งหมดของรอบพร้อมผู้พัก เรียงตามที่ทีมงานสร้าง
     *
     * @return Collection<int, ScheduleRoom>
     */
    private function rooms(TripSchedule $schedule): Collection
    {
        return ScheduleRoom::where('schedule_id', $schedule->id)
            ->with(['guests' => fn ($q) => $q->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function sameStay(int $scheduleId, ?string $stayLabel): Builder
    {
        return ScheduleRoom::where('schedule_id', $scheduleId)
            ->when($stayLabel === null, fn ($q) => $q->whereNull('stay_label'), fn ($q) => $q->where('stay_label', $stayLabel));
    }

    private function stay(?string $label): ?string
    {
        $label = trim((string) $label);

        return $label === '' ? null : $label;
    }

    private function send(int $userId, string $title, string $body, int $scheduleId): void
    {
        try {
            $this->fcm->sendToUser($userId, $title, $body, [
                'type' => 'room_assignment',
                'route' => 'chat',
                'schedule_id' => (string) $scheduleId,
            ], ['android_channel' => SendChatPushJob::ANDROID_CHANNEL]);
        } catch (\Throwable $e) {
            Log::warning('ScheduleRoomService: ส่ง push ห้องพักไม่สำเร็จ', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
