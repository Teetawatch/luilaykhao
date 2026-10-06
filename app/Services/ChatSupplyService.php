<?php

namespace App\Services;

use App\Events\ChatSupplyRequestsUpdated;
use App\Jobs\SendChatPushJob;
use App\Models\Booking;
use App\Models\ChatSupplyRequest;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ขอยา / ของจำเป็นจากสตาฟ ส่งถึงที่นั่ง
 *
 * บนรถมีคนเมารถ ปวดหัว รองเท้ากัด แล้วต้องตะโกนบอกสตาฟข้ามรถ — ลูกทริปกดขอ
 * ระบบแนบเลขที่นั่งจากตอนจองให้ สตาฟเห็นคิว "C3 · มานะ — ยาแก้เมารถ" แล้วกดส่งแล้ว
 *
 * ต่างจากคำขอแวะห้องน้ำตรงที่ "บอกชื่อ" (สตาฟต้องเดินไปให้ถูกคน) แต่ก็ไม่กระจาย
 * เข้าห้องรวม — "ใครขอยาอะไร" เป็นเรื่องสุขภาพ ห้องได้แค่จำนวนที่ค้าง ส่วนรายละเอียด
 * ทีมงานดึงผ่าน GET และเจ้าของเห็นเฉพาะของตัวเอง
 */
class ChatSupplyService
{
    /** สตาฟเห็นรายการที่จัดการแล้วย้อนหลังกี่ชั่วโมง */
    public const HANDLED_HISTORY_HOURS = 24;

    public function __construct(
        private TripRosterService $roster,
        private FcmService $fcm,
    ) {}

    public function request(User $user, TripSchedule $schedule, string $item, ?int $passengerId, ?string $note): ChatSupplyRequest
    {
        if ($item === 'other' && trim((string) $note) === '') {
            throw new \Exception('บอกหน่อยว่าต้องการอะไร');
        }

        $mine = $this->roster->passengers($schedule)->where('user_id', $user->id)->values();
        if ($passengerId !== null && ! $mine->contains('passenger_id', $passengerId)) {
            throw new \Exception('ขอแทนได้เฉพาะตัวเองและคนในกลุ่มที่คุณจองให้');
        }
        $passenger = $passengerId !== null
            ? $mine->firstWhere('passenger_id', $passengerId)
            : $mine->first();

        // ขอของเดิมให้คนเดิมซ้ำระหว่างรอ = คำขอเดิม (กดซ้ำเพราะร้อนใจ ไม่ใช่ขอเพิ่ม)
        $existing = ChatSupplyRequest::where('schedule_id', $schedule->id)
            ->where('user_id', $user->id)
            ->where('item', $item)
            ->where('passenger_id', $passenger['passenger_id'] ?? null)
            ->whereNull('delivered_at')
            ->whereNull('declined_at')
            ->first();
        if ($existing && $item !== 'other') {
            return $existing;
        }

        $open = ChatSupplyRequest::where('schedule_id', $schedule->id)
            ->where('user_id', $user->id)
            ->whereNull('delivered_at')
            ->whereNull('declined_at')
            ->count();
        if ($open >= ChatSupplyRequest::MAX_OPEN_PER_USER) {
            throw new \Exception('มีคำขอที่รอทีมงานอยู่หลายรายการแล้ว รอสักครู่นะครับ');
        }

        $request = ChatSupplyRequest::create([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'passenger_id' => $passenger['passenger_id'] ?? null,
            'item' => $item,
            'note' => trim((string) $note) ?: null,
            'seat_label' => $passenger ? ($this->seatLabels($schedule)[$passenger['passenger_id']] ?? null) : null,
        ]);

        $this->broadcastCount($schedule);

        $meta = ChatSupplyRequest::ITEMS[$item];
        $who = $this->whoLabel($request->fresh(['user']), $passenger['name'] ?? null);
        foreach ($this->staffIds($schedule) as $staffId) {
            // ไม่ใส่ tag — แต่ละคำขอคือคนละคน ห้ามทับกันในถาดจนสตาฟมองข้าม
            $this->send($staffId, "{$meta['emoji']} {$who} ขอ{$meta['label']}", $request->note ?: 'เปิดแชทเพื่อดูคิวคำขอ', $schedule);
        }

        return $request;
    }

    public function cancel(ChatSupplyRequest $request): void
    {
        if ($request->status() !== 'pending') {
            throw new \Exception('ทีมงานจัดการคำขอนี้ไปแล้ว');
        }

        $request->delete();
        $this->broadcastCount($request->schedule);
    }

    public function deliver(User $staff, ChatSupplyRequest $request): ChatSupplyRequest
    {
        if ($request->status() === 'delivered') {
            return $request;
        }

        $request->update([
            'delivered_at' => now(),
            'declined_at' => null,
            'decline_note' => null,
            'handled_by_id' => $staff->id,
        ]);
        $this->broadcastCount($request->schedule);

        return $request->fresh();
    }

    /** ไม่มีของนี้ — บอกคนขอ (เขารออยู่ ไม่ใช่เดินมาส่งแล้วไม่มีใครบอก) */
    public function decline(User $staff, ChatSupplyRequest $request, ?string $note): ChatSupplyRequest
    {
        if ($request->status() !== 'pending') {
            throw new \Exception('คำขอนี้จัดการไปแล้ว');
        }

        $request->update([
            'declined_at' => now(),
            'decline_note' => $note ?: null,
            'handled_by_id' => $staff->id,
        ]);
        $this->broadcastCount($request->schedule);

        $meta = ChatSupplyRequest::ITEMS[$request->item] ?? ['label' => $request->item, 'emoji' => '📦'];
        $this->send(
            (int) $request->user_id,
            "😔 ตอนนี้ทีมงานไม่มี{$meta['label']}",
            $note ?: 'ขอโทษนะครับ ถ้าเร่งด่วนทักทีมงานในแชทได้เลย',
            $request->schedule,
        );

        return $request->fresh();
    }

    /**
     * คิวของทีมงาน — ที่รออยู่ก่อน (เก่าสุดก่อน) ตามด้วยที่จัดการแล้วใน 24 ชม.
     *
     * @return Collection<int, ChatSupplyRequest>
     */
    public function queue(TripSchedule $schedule): Collection
    {
        $pending = ChatSupplyRequest::where('schedule_id', $schedule->id)
            ->whereNull('delivered_at')->whereNull('declined_at')
            ->with('user')->orderBy('id')->get();

        $handled = ChatSupplyRequest::where('schedule_id', $schedule->id)
            ->where(fn ($q) => $q->whereNotNull('delivered_at')->orWhereNotNull('declined_at'))
            ->where('updated_at', '>=', now()->subHours(self::HANDLED_HISTORY_HOURS))
            ->with('user')->latest('updated_at')->limit(30)->get();

        return $pending->concat($handled)->values();
    }

    /** @return Collection<int, ChatSupplyRequest> */
    public function mine(User $user, TripSchedule $schedule): Collection
    {
        return ChatSupplyRequest::where('schedule_id', $schedule->id)
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHours(self::HANDLED_HISTORY_HOURS))
            ->with('user')->latest('id')->get();
    }

    public function pendingCount(TripSchedule $schedule): int
    {
        return ChatSupplyRequest::where('schedule_id', $schedule->id)
            ->whereNull('delivered_at')->whereNull('declined_at')->count();
    }

    /** @return array<string, mixed> */
    public function present(ChatSupplyRequest $request, ?Collection $names = null): array
    {
        $meta = ChatSupplyRequest::ITEMS[$request->item] ?? ['label' => $request->item, 'emoji' => '📦'];
        $passengerName = $request->passenger_id ? ($names?->get($request->passenger_id)) : null;

        return [
            'id' => $request->id,
            'item' => $request->item,
            'label' => $meta['label'],
            'emoji' => $meta['emoji'],
            'note' => $request->note,
            'seat_label' => $request->seat_label,
            'for_name' => $passengerName ?: ($request->user?->nickname ?: $request->user?->name),
            'status' => $request->status(),
            'decline_note' => $request->decline_note,
            'created_at' => $request->created_at?->toISOString(),
        ];
    }

    /** ชื่อเล่นของผู้โดยสารทุกคนในรอบ — ใช้แสดงว่าขอให้ใคร */
    public function passengerNames(TripSchedule $schedule): Collection
    {
        return $this->roster->passengers($schedule)->pluck('name', 'passenger_id');
    }

    /**
     * เลขที่นั่งของผู้โดยสารแต่ละคน — จับคู่ด้วยชื่อแบบเดียวกับผังที่นั่งของสตาฟ
     * ใบจองที่มีผู้โดยสารคนเดียวกับที่นั่งเดียว ถือว่าเป็นของกันแม้ชื่อไม่ตรง
     * รถหลายคันต่อชื่อคันไว้ข้างหน้า ("คันที่ 2 · C3")
     *
     * @return array<int, string>
     */
    public function seatLabels(TripSchedule $schedule): array
    {
        $bookings = Booking::where('schedule_id', $schedule->id)
            ->whereIn('status', TripRosterService::STATUSES)
            ->with(['passengers:id,booking_id,name', 'seats:id,booking_id,seat_id,passenger_name'])
            ->get(['id', 'vehicle_option_label']);

        $labels = [];
        foreach ($bookings as $booking) {
            $byName = $booking->seats->keyBy(fn ($s) => trim((string) $s->passenger_name));
            $soloSeat = $booking->seats->count() === 1 && $booking->passengers->count() === 1
                ? $booking->seats->first()
                : null;

            foreach ($booking->passengers as $p) {
                $seat = $byName->get(trim((string) $p->name)) ?? $soloSeat;
                if (! $seat) {
                    continue;
                }
                $prefix = trim((string) $booking->vehicle_option_label);
                $labels[(int) $p->id] = ($prefix !== '' ? "{$prefix} · " : '').$seat->seat_id;
            }
        }

        return $labels;
    }

    private function whoLabel(ChatSupplyRequest $request, ?string $passengerName): string
    {
        $name = $passengerName ?: ($request->user?->nickname ?: $request->user?->name ?: 'ลูกทริป');

        return $request->seat_label ? "{$request->seat_label} {$name}" : $name;
    }

    private function broadcastCount(?TripSchedule $schedule): void
    {
        if ($schedule) {
            broadcast(new ChatSupplyRequestsUpdated($schedule->id, $this->pendingCount($schedule)));
        }
    }

    /** @return Collection<int, int> */
    private function staffIds(TripSchedule $schedule): Collection
    {
        return $schedule->activeStaff()->pluck('users.id')->map(fn ($id) => (int) $id)->values();
    }

    private function send(int $userId, string $title, string $body, TripSchedule $schedule, ?string $tag = null): void
    {
        try {
            $this->fcm->sendToUser($userId, $title, $body, [
                'type' => 'supply_request',
                'route' => 'chat',
                'schedule_id' => (string) $schedule->id,
            ], array_filter([
                'tag' => $tag,
                'android_channel' => SendChatPushJob::ANDROID_CHANNEL,
            ]));
        } catch (\Throwable $e) {
            Log::warning('ChatSupplyService: ส่ง push ไม่สำเร็จ', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
