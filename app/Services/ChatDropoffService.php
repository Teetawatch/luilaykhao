<?php

namespace App\Services;

use App\Events\ChatDropoffUpdated;
use App\Events\ChatMessageSent;
use App\Jobs\SendChatPushJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatDropoffChoice;
use App\Models\ChatDropoffRound;
use App\Models\ChatMessage;
use App\Models\SchedulePickupPoint;
use App\Models\ScheduleVehicleOption;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ขอลงระหว่างทางขากลับ
 *
 * ปัญหาจริง: ขากลับมีคนขอลงปั๊ม แยก BTS ก่อนถึงจุดส่ง แล้วตะโกนบอกคนขับกันทีละคน
 * ลืมบ้าง ข้ามบ้าง — การ์ดนี้ให้แต่ละคนบอกจุดลงล่วงหน้า (ไม่บอก = จุดรับขาไป)
 * ทีมงานได้รายการจุดเรียงตามทางจากตำแหน่งรถ และพอรถเข้าใกล้จุดไหน คนที่จะลงจุดนั้น
 * ได้แจ้งเตือนให้เตรียมของ (ใช้ GPS ของรถคันที่คนนั้นนั่ง)
 *
 * ความเป็นส่วนตัว: จุดลงที่พิมพ์เองอาจเป็นหน้าบ้าน — ห้องรวมเห็นแค่จำนวน
 * จุดของตัวเองเห็นเฉพาะบัญชีที่ดูแล รายการเต็มเห็นเฉพาะทีมงาน
 */
class ChatDropoffService
{
    public function __construct(
        private ChatService $chatService,
        private TripRosterService $roster,
        private FcmService $fcm,
        private VehicleLocationService $vehicleLocations,
    ) {}

    public function open(User $staff, TripSchedule $schedule): ChatDropoffRound
    {
        if ($this->rosterOf($schedule)->isEmpty()) {
            throw new \Exception('ยังไม่มีผู้โดยสารที่นั่งรถกลับกับทีมงานในรอบนี้');
        }

        $round = DB::transaction(function () use ($staff, $schedule) {
            // สตาฟสองคนกดพร้อมกันต้องได้การ์ดใบเดียว
            TripSchedule::whereKey($schedule->id)->lockForUpdate()->first();

            if (ChatDropoffRound::where('schedule_id', $schedule->id)->whereNull('closed_at')->exists()) {
                throw new \Exception('มีการ์ดจุดลงขากลับเปิดอยู่แล้วในห้องนี้ครับ');
            }

            $round = ChatDropoffRound::create([
                'schedule_id' => $schedule->id,
                'created_by_id' => $staff->id,
            ]);

            $message = ChatMessage::create([
                'schedule_id' => $schedule->id,
                'user_id' => $staff->id,
                'sender_role' => $this->chatService->senderRole($staff, $schedule),
                'body' => '🚐 ขากลับลงที่ไหน? — ใครจะลงระหว่างทาง (ปั๊ม แยก BTS) เลือกจุดลงในการ์ดนี้ได้เลย '
                    .'ไม่เลือก = ลงที่จุดรับเดิม ใกล้ถึงแล้วระบบเตือนให้เตรียมของ',
            ]);

            $round->update(['message_id' => $message->id]);

            return $round;
        });

        $this->ensureRows($round);

        $message = $round->message()->with(['user', 'replyTo.user', 'reactions', 'dropoffRound'])->first();
        if ($message) {
            broadcast(new ChatMessageSent($message))->toOthers();
        }

        return $round->fresh();
    }

    /**
     * เลือกจุดลง — [pickupPointId] = จุดรับของรอบนี้, [label] (+พิกัด) = จุดที่พิมพ์เอง,
     * ไม่ส่งทั้งคู่ = กลับไปใช้จุดรับขาไป ลูกทริปเลือกได้เฉพาะคนที่ตัวเองดูแล
     *
     * @param  array<int, int>  $passengerIds
     */
    public function choose(
        User $user,
        ChatDropoffRound $round,
        array $passengerIds,
        ?int $pickupPointId,
        ?string $label,
        ?float $lat,
        ?float $lng,
        ?string $note,
        bool $asStaff,
    ): ChatDropoffRound {
        if ($round->isClosed()) {
            throw new \Exception('ปิดการ์ดจุดลงไปแล้ว บอกทีมงานในแชทได้เลยครับ');
        }

        $this->ensureRows($round);
        $roster = $this->rosterOf($round->schedule);
        $allowed = $asStaff
            ? $roster->pluck('passenger_id')
            : $roster->where('user_id', $user->id)->pluck('passenger_id');
        $ids = collect($passengerIds)->map(fn ($id) => (int) $id)->unique()
            ->filter(fn ($id) => $allowed->contains($id))
            ->values();

        if ($ids->isEmpty()) {
            throw new \Exception($asStaff ? 'ไม่พบผู้โดยสารในรอบนี้' : 'เลือกได้เฉพาะตัวเองและคนในกลุ่มที่คุณจองให้');
        }

        $dropped = ChatDropoffChoice::where('round_id', $round->id)->whereIn('passenger_id', $ids)->whereNotNull('dropped_at')->exists();
        if ($dropped) {
            throw new \Exception('มีคนในรายชื่อที่ลงรถไปแล้ว');
        }

        $note = $note !== null ? (trim($note) ?: null) : null;

        if ($pickupPointId !== null) {
            $point = SchedulePickupPoint::where('schedule_id', $round->schedule_id)->find($pickupPointId);
            if (! $point) {
                throw new \Exception('ไม่พบจุดนี้ในรอบนี้');
            }
            $values = [
                'pickup_point_id' => $point->id,
                'label' => $this->pointLabel($point),
                'latitude' => $point->latitude,
                'longitude' => $point->longitude,
                'note' => $note,
                'chosen_by_id' => $user->id,
            ];
        } elseif ($label !== null && trim($label) !== '') {
            if (($lat === null) !== ($lng === null)) {
                throw new \Exception('พิกัดไม่ครบ');
            }
            $values = [
                'pickup_point_id' => null,
                'label' => trim($label),
                'latitude' => $lat,
                'longitude' => $lng,
                'note' => $note,
                'chosen_by_id' => $user->id,
            ];
        } else {
            $values = null;
        }

        DB::transaction(function () use ($round, $ids, $values) {
            foreach ($ids as $id) {
                $choice = ChatDropoffChoice::where('round_id', $round->id)->where('passenger_id', $id)->first();
                $next = $values ?? [...$this->defaultFor($round->schedule, $id), 'note' => null, 'chosen_by_id' => null];

                $moved = ! $choice
                    || $choice->latitude != $next['latitude']
                    || $choice->longitude != $next['longitude']
                    || $choice->label !== $next['label'];

                ChatDropoffChoice::updateOrCreate(
                    ['round_id' => $round->id, 'passenger_id' => $id],
                    // ย้ายจุด = ต้องเตือนใหม่ตอนรถเข้าใกล้จุดใหม่
                    [...$next, ...($moved ? ['approach_notified_at' => null] : [])],
                );
            }
        });

        return $this->broadcastUpdate($round);
    }

    /**
     * ทีมงานกดส่งถึงแล้ว (หรือยกเลิก) — ปกติกดทีละจุด ทุกคนที่ลงจุดนั้นพร้อมกัน
     * ส่งครบทุกคน = ปิดการ์ดเองพร้อมประกาศ
     *
     * @param  array<int, int>  $passengerIds
     */
    public function setDropped(User $staff, ChatDropoffRound $round, array $passengerIds, bool $dropped): ChatDropoffRound
    {
        if ($round->isClosed() && $dropped) {
            throw new \Exception('ปิดการ์ดจุดลงไปแล้ว');
        }

        $this->ensureRows($round);
        $ids = collect($passengerIds)->map(fn ($id) => (int) $id)->unique();

        $query = ChatDropoffChoice::where('round_id', $round->id)->whereIn('passenger_id', $ids);
        if (! (clone $query)->exists()) {
            throw new \Exception('ไม่พบผู้โดยสารในรายการจุดลง');
        }

        $query->update($dropped
            ? ['dropped_at' => now(), 'dropped_by_id' => $staff->id]
            : ['dropped_at' => null, 'dropped_by_id' => null]);

        if ($dropped) {
            $this->finishIfComplete($round);
        }

        return $this->broadcastUpdate($round);
    }

    public function close(ChatDropoffRound $round): ChatDropoffRound
    {
        $claimed = ChatDropoffRound::whereKey($round->id)->whereNull('closed_at')->update(['closed_at' => now()]);
        if ($claimed === 0) {
            return $round->fresh();
        }

        $fresh = $this->broadcastUpdate($round);
        $p = $this->present($fresh);
        $this->chatService->postSystem(
            $fresh->schedule,
            $p['dropped_count'] >= $p['total']
                ? "🚐 ส่งครบทุกจุดแล้ว ({$p['total']} คน) ขอบคุณที่เดินทางกับเราครับ 🙏"
                : "🚐 ปิดการ์ดจุดลงแล้ว (ส่งแล้ว {$p['dropped_count']}/{$p['total']} คน)",
        );

        return $fresh;
    }

    /**
     * งานตามเวลา (ทุกนาที): รถเข้าใกล้จุดลงในรัศมี APPROACH_KM → เตือนคนที่จะลงจุดนั้น
     * ให้เตรียมของ และบอกทีมงานว่าจุดถัดไปลงกี่คน, การ์ดที่ลืมปิดปิดเอง
     */
    public function settleDue(): int
    {
        $handled = 0;

        ChatDropoffRound::whereNull('closed_at')
            ->whereNotNull('message_id')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (ChatDropoffRound $round) use (&$handled) {
                try {
                    if ($round->created_at->lte(now()->subHours(ChatDropoffRound::AUTO_CLOSE_AFTER_HOURS))) {
                        $this->close($round);
                    } else {
                        $this->notifyApproaching($round);
                    }
                    $handled++;
                } catch (\Throwable $e) {
                    Log::warning('SettleChatPolls: จุดลงขากลับทำงานไม่สำเร็จ', ['round_id' => $round->id, 'error' => $e->getMessage()]);
                }
            });

        return $handled;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ChatDropoffRound $round, ?int $viewerId = null, bool $withStaffDetail = false): array
    {
        $schedule = $round->schedule;
        $roster = $this->rosterOf($schedule);
        $rows = $this->rows($round, $roster);

        $payload = [
            'id' => $round->id,
            'is_closed' => $round->isClosed(),
            'total' => $rows->count(),
            'dropped_count' => $rows->where('dropped', true)->count(),
            'stop_count' => $rows->pluck('stop_key')->unique()->count(),
            'stops_done' => $rows->groupBy('stop_key')->filter(fn ($g) => $g->every(fn ($r) => $r['dropped']))->count(),
            'created_at' => $round->created_at?->toISOString(),
        ];

        if ($viewerId !== null) {
            $payload['mine'] = $rows->where('user_id', $viewerId)->values()->all();
        }

        if ($withStaffDetail) {
            $van = $this->freshLocation($schedule->vehicle_id ? (int) $schedule->vehicle_id : null, $schedule);
            $payload['detail'] = [
                'stops' => $this->orderedStops($rows, $van, $schedule),
                'van' => $van ? ['lat' => $van['lat'], 'lng' => $van['lng'], 'recorded_at' => $van['recorded_at']] : null,
            ];
        }

        return $payload;
    }

    /**
     * จุดที่เลือกได้ — จุดรับทุกจุดของรอบ (ขากลับรถวิ่งผ่านเส้นเดิม)
     *
     * @return array<int, array<string, mixed>>
     */
    public function options(TripSchedule $schedule): array
    {
        return SchedulePickupPoint::where('schedule_id', $schedule->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (SchedulePickupPoint $p) => [
                'id' => $p->id,
                'label' => $this->pointLabel($p),
                'region' => $p->region_label ?: $p->region,
                'lat' => $p->latitude,
                'lng' => $p->longitude,
            ])->values()->all();
    }

    // ── ภายใน ───────────────────────────────────────────────────────────────

    /**
     * สร้างแถวให้ทุกคนที่ยังไม่มี (ค่าตั้งต้น = จุดรับขาไป) — คนที่จองเข้ามาทีหลังก็ได้แถว
     */
    private function ensureRows(ChatDropoffRound $round): void
    {
        $roster = $this->rosterOf($round->schedule);
        $have = ChatDropoffChoice::where('round_id', $round->id)->pluck('passenger_id')->map(fn ($id) => (int) $id);

        foreach ($roster->pluck('passenger_id')->diff($have) as $id) {
            ChatDropoffChoice::firstOrCreate(
                ['round_id' => $round->id, 'passenger_id' => $id],
                $this->defaultFor($round->schedule, (int) $id),
            );
        }
    }

    /**
     * จุดลงตั้งต้นของผู้โดยสาร = จุดรับขาไป: จุดรายคน → จุดของใบจอง → จุดรับที่ขอเอง
     * (อนุมัติแล้ว) → ไม่รู้จุด (ทีมงานเห็นว่า "ยังไม่ระบุ")
     *
     * @return array{pickup_point_id: ?int, label: string, latitude: ?float, longitude: ?float}
     */
    private function defaultFor(TripSchedule $schedule, int $passengerId): array
    {
        $passenger = BookingPassenger::with('booking')->find($passengerId);
        $booking = $passenger?->booking;

        $pointId = $passenger?->pickup_point_id ?: $booking?->pickup_point_id;
        $point = $pointId ? SchedulePickupPoint::where('schedule_id', $schedule->id)->find($pointId) : null;

        if ($point) {
            return [
                'pickup_point_id' => $point->id,
                'label' => $this->pointLabel($point),
                'latitude' => $point->latitude,
                'longitude' => $point->longitude,
            ];
        }

        if ($booking && $booking->custom_pickup_status === Booking::CUSTOM_PICKUP_APPROVED && $booking->custom_pickup_label) {
            return [
                'pickup_point_id' => null,
                'label' => (string) $booking->custom_pickup_label,
                'latitude' => $booking->custom_pickup_lat,
                'longitude' => $booking->custom_pickup_lng,
            ];
        }

        return ['pickup_point_id' => null, 'label' => 'ยังไม่ระบุจุดลง', 'latitude' => null, 'longitude' => null];
    }

    /**
     * แถวรายคน รวมจุดตั้งต้นของคนที่ยังไม่มีแถว
     *
     * @param  Collection<int, array<string, mixed>>  $roster
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(ChatDropoffRound $round, Collection $roster): Collection
    {
        $choices = ChatDropoffChoice::where('round_id', $round->id)->get()->keyBy('passenger_id');

        return $roster->map(function ($p) use ($choices, $round) {
            $c = $choices->get($p['passenger_id']);
            $data = $c ? [
                'pickup_point_id' => $c->pickup_point_id ? (int) $c->pickup_point_id : null,
                'label' => $c->label,
                'latitude' => $c->latitude,
                'longitude' => $c->longitude,
            ] : $this->defaultFor($round->schedule, $p['passenger_id']);

            return [
                'id' => $p['passenger_id'],
                'name' => $p['name'],
                'user_id' => $p['user_id'],
                'booking_id' => $p['booking_id'],
                'pickup_point_id' => $data['pickup_point_id'],
                'label' => $data['label'],
                'lat' => $data['latitude'],
                'lng' => $data['longitude'],
                'note' => $c?->note,
                'is_default' => ! $c || $c->chosen_by_id === null,
                'dropped' => $c?->dropped_at !== null,
                'stop_key' => $this->stopKey($data['pickup_point_id'], $data['label'], $data['latitude'], $data['longitude']),
            ];
        })->values();
    }

    private function stopKey(?int $pointId, string $label, ?float $lat, ?float $lng): string
    {
        if ($pointId) {
            return "pp:{$pointId}";
        }
        if ($lat !== null && $lng !== null) {
            return sprintf('c:%.4f,%.4f', $lat, $lng);
        }

        return 'l:'.mb_strtolower(trim($label));
    }

    /**
     * จุดลงเรียงตามทาง — มีพิกัดรถสด ๆ: ไล่จุดที่ใกล้ที่สุดต่อกันไปจากรถ (nearest
     * neighbour) ไม่มี: เริ่มจากจุดรับที่รับคนท้ายสุดตอนขาไป (ขากลับมักย้อนเส้นเดิม)
     * จุดที่ไม่มีพิกัดต่อท้าย จุดที่ส่งแล้วไว้ล่างสุด
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array{lat: float, lng: float, recorded_at: string}|null  $van
     * @return array<int, array<string, mixed>>
     */
    private function orderedStops(Collection $rows, ?array $van, TripSchedule $schedule): array
    {
        $stops = $rows->groupBy('stop_key')->map(function (Collection $group, string $key) use ($van) {
            $first = $group->first();

            return [
                'key' => $key,
                'label' => $first['label'],
                'pickup_point_id' => $first['pickup_point_id'],
                'lat' => $first['lat'],
                'lng' => $first['lng'],
                'count' => $group->count(),
                'dropped' => $group->every(fn ($r) => $r['dropped']),
                'distance_km' => ($van && $first['lat'] !== null && $first['lng'] !== null)
                    ? round($this->distanceKm($van['lat'], $van['lng'], (float) $first['lat'], (float) $first['lng']), 1)
                    : null,
                'passengers' => $group->map(fn ($r) => [
                    'id' => $r['id'],
                    'name' => $r['name'],
                    'note' => $r['note'],
                    'is_default' => $r['is_default'],
                    'dropped' => $r['dropped'],
                ])->values()->all(),
            ];
        })->values();

        $pending = $stops->where('dropped', false);
        $located = $pending->filter(fn ($s) => $s['lat'] !== null && $s['lng'] !== null)->values();
        $unlocated = $pending->filter(fn ($s) => $s['lat'] === null || $s['lng'] === null)->values();

        if ($van) {
            $from = ['lat' => $van['lat'], 'lng' => $van['lng']];
        } else {
            $sortOrder = SchedulePickupPoint::where('schedule_id', $schedule->id)->pluck('sort_order', 'id');
            $start = $located->sortByDesc(fn ($s) => $s['pickup_point_id'] ? (int) ($sortOrder[$s['pickup_point_id']] ?? 0) : -1)->first();
            $from = $start ? ['lat' => (float) $start['lat'], 'lng' => (float) $start['lng']] : null;
        }

        $ordered = [];
        $left = $located->all();
        while ($from && $left !== []) {
            $bestIdx = null;
            $bestKm = INF;
            foreach ($left as $i => $s) {
                $km = $this->distanceKm($from['lat'], $from['lng'], (float) $s['lat'], (float) $s['lng']);
                if ($km < $bestKm) {
                    $bestKm = $km;
                    $bestIdx = $i;
                }
            }
            $next = $left[$bestIdx];
            $ordered[] = $next;
            $from = ['lat' => (float) $next['lat'], 'lng' => (float) $next['lng']];
            unset($left[$bestIdx]);
        }

        return [
            ...$ordered,
            ...$unlocated->all(),
            ...$stops->where('dropped', true)->values()->all(),
        ];
    }

    private function notifyApproaching(ChatDropoffRound $round): void
    {
        $this->ensureRows($round);
        $schedule = $round->schedule;
        $roster = $this->rosterOf($schedule)->keyBy('passenger_id');

        $choices = ChatDropoffChoice::where('round_id', $round->id)
            ->whereNull('dropped_at')
            ->whereNull('approach_notified_at')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->filter(fn (ChatDropoffChoice $c) => $c->hasCoordinates() && $roster->has($c->passenger_id));

        if ($choices->isEmpty()) {
            return;
        }

        $vehicleByBooking = $this->vehicleByBooking($schedule, $roster->pluck('booking_id')->unique()->values());
        $locations = [];
        $due = $choices->filter(function (ChatDropoffChoice $c) use ($roster, $vehicleByBooking, $schedule, &$locations) {
            $vehicleId = $vehicleByBooking[$roster[$c->passenger_id]['booking_id']] ?? null;
            $van = $locations[$vehicleId ?? 0] ??= $this->freshLocation($vehicleId, $schedule);

            return $van !== null
                && $this->distanceKm($van['lat'], $van['lng'], (float) $c->latitude, (float) $c->longitude) <= ChatDropoffRound::APPROACH_KM;
        });

        if ($due->isEmpty()) {
            return;
        }

        // จองสิทธิ์ก่อนส่ง — job ซ้อนกันจะไม่เด้งซ้ำ
        $claimed = $due->filter(fn (ChatDropoffChoice $c) => ChatDropoffChoice::whereKey($c->id)
            ->whereNull('approach_notified_at')
            ->update(['approach_notified_at' => now()]) > 0);
        if ($claimed->isEmpty()) {
            return;
        }

        $data = [
            'type' => 'dropoff_soon',
            'route' => 'chat',
            'schedule_id' => (string) $schedule->id,
            'message_id' => (string) $round->message_id,
        ];

        foreach ($claimed->groupBy(fn ($c) => $roster[$c->passenger_id]['user_id'] ?? 0) as $userId => $mine) {
            if (! $userId) {
                continue;
            }
            $labels = $mine->pluck('label')->unique()->join(', ');
            $names = $mine->map(fn ($c) => $roster[$c->passenger_id]['name']);
            $who = $names->count() > 1 ? ' ('.$names->join(', ').')' : '';
            $this->send((int) $userId, [
                "🚐 ใกล้ถึงจุดลงของคุณแล้ว{$who}",
                "{$labels} — เก็บของให้พร้อม ตรวจของบนรถให้ครบก่อนลงนะครับ 🎒",
            ], $data, channel: 'important_updates');
        }

        // ทีมงาน: จุดถัดไปลงกี่คน ใครบ้าง — ครั้งเดียวต่อจุด (ทุกคนในจุดเดียวกันถูกเตือนพร้อมกัน)
        $staffIds = $schedule->activeStaff()->pluck('users.id')->map(fn ($id) => (int) $id);
        foreach ($claimed->groupBy('label') as $label => $group) {
            $names = $group->map(fn ($c) => $roster[$c->passenger_id]['name'])->join(', ');
            foreach ($staffIds as $staffId) {
                $this->send($staffId, ["🚐 ใกล้ถึง {$label} — ลง {$group->count()} คน", $names], $data);
            }
        }

        $this->broadcastUpdate($round);
    }

    /**
     * รถของแต่ละใบจอง — ใบจองที่เลือกพาหนะ (รถตู้/รถบัส) ใช้รถของตัวเลือกนั้น
     * ไม่งั้นรถหลักของรอบ
     *
     * @param  Collection<int, int>  $bookingIds
     * @return array<int, int|null>
     */
    private function vehicleByBooking(TripSchedule $schedule, Collection $bookingIds): array
    {
        $optionVehicle = ScheduleVehicleOption::where('schedule_id', $schedule->id)->pluck('vehicle_id', 'id');

        return Booking::whereIn('id', $bookingIds)->get(['id', 'vehicle_option_id'])
            ->mapWithKeys(fn (Booking $b) => [
                (int) $b->id => ($b->vehicle_option_id && ($optionVehicle[$b->vehicle_option_id] ?? null))
                    ? (int) $optionVehicle[$b->vehicle_option_id]
                    : ($schedule->vehicle_id ? (int) $schedule->vehicle_id : null),
            ])->all();
    }

    /**
     * พิกัดรถของรอบนี้ที่ยังสด — เก่ากว่า LOCATION_FRESH_MINUTES ไม่ใช้ตัดสิน
     *
     * @return array{lat: float, lng: float, recorded_at: string}|null
     */
    private function freshLocation(?int $vehicleId, TripSchedule $schedule): ?array
    {
        if (! $vehicleId) {
            return null;
        }

        $vehicle = Vehicle::find($vehicleId);
        $latest = $vehicle ? $this->vehicleLocations->latestForSchedule($vehicle, $schedule) : null;
        if (! $latest || ! isset($latest['latitude'], $latest['longitude'], $latest['recorded_at'])) {
            return null;
        }

        $recordedAt = Carbon::parse($latest['recorded_at']);
        if ($recordedAt->lt(now()->subMinutes(ChatDropoffRound::LOCATION_FRESH_MINUTES))) {
            return null;
        }

        return [
            'lat' => (float) $latest['latitude'],
            'lng' => (float) $latest['longitude'],
            'recorded_at' => $recordedAt->toISOString(),
        ];
    }

    private function finishIfComplete(ChatDropoffRound $round): void
    {
        $p = $this->present($round->fresh());
        if ($p['total'] > 0 && $p['dropped_count'] >= $p['total']) {
            $this->close($round);
        }
    }

    private function pointLabel(SchedulePickupPoint $point): string
    {
        return trim((string) $point->pickup_location) ?: ($point->region_label ?: 'จุดรับ');
    }

    /** @var array<int, Collection<int, array<string, mixed>>> */
    private array $rosterCache = [];

    /**
     * คนที่นั่งรถกลับกับทีมงาน — ไม่นับคนจอยทริป (ขับรถมาเอง) และคนที่ไม่ได้ไป
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rosterOf(TripSchedule $schedule): Collection
    {
        return $this->rosterCache[$schedule->id] ??= $this->roster->travelling($schedule, includeJoinTrip: false);
    }

    private function distanceKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @param  array{0: string, 1: string}  $text
     * @param  array<string, string>  $data
     */
    private function send(int $userId, array $text, array $data, string $channel = SendChatPushJob::ANDROID_CHANNEL): void
    {
        try {
            $this->fcm->sendToUser($userId, $text[0], $text[1], $data, ['android_channel' => $channel]);
        } catch (\Throwable $e) {
            Log::warning('ChatDropoffService: ส่ง push ไม่สำเร็จ', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    private function broadcastUpdate(ChatDropoffRound $round): ChatDropoffRound
    {
        $fresh = $round->fresh();

        broadcast(new ChatDropoffUpdated(
            (int) $fresh->schedule_id,
            (int) $fresh->message_id,
            $this->present($fresh),
        ));

        return $fresh;
    }
}
