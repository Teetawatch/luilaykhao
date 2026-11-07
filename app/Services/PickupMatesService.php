<?php

namespace App\Services;

use App\Events\ChatMessageUpdated;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\SchedulePickupPoint;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MaskedName;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * "เพื่อนรอรถจุดเดียวกัน" — ใครอีกบ้างที่ยืนรอรถอยู่จุดเดียวกับเรา
 *
 * ที่มาจากพฤติกรรมจริง: ลูกค้าที่ถึงจุดรับตอนตีสี่พิมพ์ถามในกลุ่มว่า "มีใครขึ้น
 * ตรงนี้ไหมคะ อยู่ตรงไหนคะ" — ไม่ได้อยากรู้เรื่องรถ อยากรู้ว่าไม่ได้ยืนรอคนเดียว
 * ที่นี่ตอบสองคำถามนั้นจากข้อมูลที่มีอยู่แล้ว (จุดรับรายคน + สถานะที่ลูกค้ากดเอง)
 * บวกจุดสังเกตหนึ่งบรรทัดที่ลูกค้าพิมพ์บอกกันเอง
 *
 * ขอบเขตความเป็นส่วนตัวที่ตั้งใจให้แคบ:
 * - เห็นได้เฉพาะคนในรอบเดียวกันที่ขึ้นจุดเดียวกัน และเฉพาะช่วง D-1 ถึงวันเดินทาง
 * - ชื่อเป็นชื่อเล่นหรือชื่อต้น + อักษรแรกของนามสกุล ไม่มีเบอร์ ไม่มีพิกัด
 * - ใบจองที่กด "ไม่แสดงฉัน" หายไปจากทุกอย่าง รวมถึงตัวเลขจำนวนคน — คนที่ไม่อยาก
 *   ให้ใครรู้ว่ายืนรออยู่คนเดียว การโชว์ "ถึงแล้ว 1 คน" ก็บอกเรื่องนั้นอยู่ดี
 * - การ์ดในห้องแชท (ซึ่งทั้งรอบเห็น) บอกแค่จำนวน ไม่บอกชื่อ
 */
class PickupMatesService
{
    /** จุดสังเกตยาวเกินนี้ไม่ใช่จุดสังเกตแล้ว */
    public const NOTE_MAX = 80;

    /** ชื่อที่แสดงต่อหนึ่งใบจอง ที่เหลือเป็น "+N" */
    private const NAMES_SHOWN = 2;

    private const PASSENGER_COLUMNS = PickupArrivalService::PASSENGER_PICKUP_COLUMNS.',name,nickname';

    /** สถานะที่แปลว่า "ตื่นแล้วและกำลังเดินทาง" — คนกลุ่มนี้เท่านั้นที่ได้ push */
    private const MOVING_STATUSES = [Booking::PICKUP_STATUS_ON_THE_WAY, Booking::PICKUP_STATUS_LATE];

    public function __construct(
        private PickupArrivalService $arrivals,
        private ChatService $chatService,
    ) {}

    /**
     * ควรโชว์การ์ดให้ใบจองนี้ไหม — ไม่นับว่ามีเพื่อนหรือเปล่า (ไม่มีก็บอกได้ว่าไม่มี)
     */
    public function isVisibleFor(Booking $booking): bool
    {
        $schedule = $booking->schedule;

        if (! $schedule || $booking->status !== 'confirmed' || $booking->is_join_trip) {
            return false;
        }

        if (! app(PickupStatusService::class)->isWithinWindow($schedule)) {
            return false;
        }

        // รอบที่คนยังไม่ครบ: คืนก่อนเดินทางห้ามมีอะไรที่ชวนให้เข้าใจว่าทริปไปแน่
        // ({@see TripSchedule::holdsTravelMessages()}) — แต่ถึงวันเดินทางแล้วรอบที่
        // ไม่ถูกยกเลิกคือไปจริง กติกาเดียวกับข้อความวันเดินทางอื่น ๆ
        if ($schedule->holdsTravelMessages() && $this->today() < $schedule->effectiveDepartureDate()?->toDateString()) {
            return false;
        }

        return true;
    }

    /**
     * สิ่งที่การ์ดของใบจองนี้แสดง
     *
     * @return array<string, mixed>
     */
    public function forBooking(Booking $booking): array
    {
        if (! $this->isVisibleFor($booking)) {
            return ['visible' => false];
        }

        $schedule = $booking->schedule;
        $validIds = $this->validIds($schedule);

        $booking->load('passengers:'.self::PASSENGER_COLUMNS);
        $myPointIds = $this->arrivals->effectivePickupPointIds($booking, $validIds, awaitingOnly: true);

        if ($myPointIds === []) {
            return ['visible' => false];
        }

        $bookings = $this->roundBookings($schedule);
        $avatars = $this->avatarUsers($bookings);

        $points = [];

        foreach ($schedule->pickupPoints->whereIn('id', $myPointIds)->sortBy('sort_order') as $point) {
            $points[] = $this->presentPoint($point, $booking, $bookings, $avatars, $validIds);
        }

        return [
            'visible' => true,
            'booking_ref' => $booking->booking_ref,
            'hidden' => (bool) $booking->pickup_mates_hidden,
            'my_note' => $this->freshNote($booking),
            'note_max' => self::NOTE_MAX,
            'points' => $points,
        ];
    }

    /**
     * ลูกค้าแก้จุดสังเกตของตัวเอง / ซ่อนตัวจากรายชื่อ
     *
     * @param  array{note?: string|null, hidden?: bool}  $changes
     *
     * @throws \Exception ข้อความภาษาไทยที่เอาไปแสดงได้ตรง ๆ
     */
    public function updateMine(Booking $booking, array $changes): Booking
    {
        if (! $this->isVisibleFor($booking)) {
            throw new \Exception('ตอนนี้ยังไม่ใช่ช่วงรอรถของทริปนี้');
        }

        if (array_key_exists('note', $changes)) {
            $note = trim((string) $changes['note']);
            $note = $note === '' ? null : mb_substr($note, 0, self::NOTE_MAX);

            $booking->pickup_note = $note;
            $booking->pickup_note_at = $note === null ? null : now();
        }

        if (array_key_exists('hidden', $changes)) {
            $booking->pickup_mates_hidden = (bool) $changes['hidden'];
        }

        $hiddenChanged = $booking->isDirty('pickup_mates_hidden');
        $booking->save();

        // ซ่อน/เลิกซ่อนเปลี่ยนตัวเลขในการ์ดห้องแชท
        if ($hiddenChanged) {
            $this->syncChatCards($booking);
        }

        return $booking;
    }

    /**
     * เรียกจาก {@see PickupStatusService::report()} ทุกครั้งที่ลูกค้ากดสถานะ
     *
     * คนแรกที่ถึงจุด = ข่าวที่คนกำลังเดินทางมาจุดเดียวกันอยากรู้ที่สุด ("มีคน
     * รออยู่แล้ว") จึงยิง push ครั้งเดียวต่อจุด และเฉพาะคนที่กดว่ากำลังไป/อาจสาย
     * — คนที่ยังไม่ได้กดอะไรอาจยังหลับอยู่ ตีสามไม่ใช่เวลาปลุกด้วยเรื่องนี้
     */
    public function statusReported(Booking $booking, ?string $previousStatus): void
    {
        if (! $this->isVisibleFor($booking)) {
            return;
        }

        $created = $this->syncChatCards($booking);

        $justArrived = $booking->pickup_status === Booking::PICKUP_STATUS_ARRIVED
            && $previousStatus !== Booking::PICKUP_STATUS_ARRIVED;

        if (! $justArrived || $booking->pickup_mates_hidden) {
            return;
        }

        foreach ($created as $point) {
            $this->notifyHeadingTo($booking, $point);
        }
    }

    /**
     * การ์ดห้องแชทหนึ่งใบต่อจุด ("มีเพื่อนรออยู่ที่…แล้ว N คน") อัปเดตในที่เดิม
     *
     * @return array<int, SchedulePickupPoint> จุดที่เพิ่งสร้างการ์ดครั้งแรก
     */
    private function syncChatCards(Booking $booking): array
    {
        $schedule = $booking->schedule;
        $validIds = $this->validIds($schedule);

        $booking->load('passengers:'.self::PASSENGER_COLUMNS);
        $pointIds = $this->arrivals->effectivePickupPointIds($booking, $validIds, awaitingOnly: true);

        if ($pointIds === []) {
            return [];
        }

        $bookings = $this->roundBookings($schedule);
        $created = [];

        foreach ($schedule->pickupPoints->whereIn('id', $pointIds) as $point) {
            // รถถึงแล้ว = การ์ด "รถถึงจุดรับแล้ว" ของสตาฟตอบทุกอย่างแทนแล้ว
            if ($this->vanArrivedAt($point) !== null) {
                continue;
            }

            [$people, $arrived] = $this->countAt($point, $bookings, $validIds);

            if ($this->upsertChatCard($schedule, $point, $people, $arrived)) {
                $created[] = $point;
            }
        }

        return $created;
    }

    /** @return bool true เมื่อเพิ่งโพสต์การ์ดใบนี้ครั้งแรก */
    private function upsertChatCard(TripSchedule $schedule, SchedulePickupPoint $point, int $people, int $arrived): bool
    {
        $key = $this->chatKey($point);
        $existing = ChatMessage::where('schedule_id', $schedule->id)
            ->where('system_key', $key)
            ->first();

        // ยังไม่มีใครถึง ไม่มีอะไรจะบอก — และถ้าเคยมีการ์ดแล้ว ปล่อยตัวเลขเดิมไว้
        // ดีกว่าแก้เป็น "0 คน" ที่อ่านแล้วเหมือนเพื่อนทิ้งไป
        if ($arrived < 1) {
            return false;
        }

        $body = $this->chatBody($point, $people, $arrived);

        if ($existing) {
            $this->updateChatMessage($existing, $body);

            return false;
        }

        $this->chatService->ensureWelcome($schedule);

        try {
            $this->chatService->postSystem($schedule, $body, $key);
        } catch (QueryException $e) {
            // สองคนกดถึงแล้วพร้อมกัน — system_key เป็น unique คนแพ้จังหวะแค่อัปเดต
            $raced = ChatMessage::where('schedule_id', $schedule->id)
                ->where('system_key', $key)
                ->first();

            if (! $raced) {
                throw $e;
            }

            $this->updateChatMessage($raced, $body);

            return false;
        }

        return true;
    }

    private function updateChatMessage(ChatMessage $message, string $body): void
    {
        $message->body = $body;

        if (! $message->isDirty()) {
            return;
        }

        $message->save();
        broadcast(new ChatMessageUpdated($message));
    }

    private function chatBody(SchedulePickupPoint $point, int $people, int $arrived): string
    {
        $label = $this->arrivals->label($point);
        $of = $people > $arrived ? " จาก {$people} คน" : '';

        return "🙋 มีเพื่อนร่วมทริปรออยู่ที่{$label}แล้ว {$arrived} คน{$of}\n"
            .'ใครขึ้นจุดนี้ เปิดหน้า "วันเดินทาง" ในแอป ดูได้ว่าเพื่อนยืนรอตรงไหน แล้วไปรอด้วยกันได้เลยครับ';
    }

    private function chatKey(SchedulePickupPoint $point): string
    {
        return "pickup_mates_{$point->id}";
    }

    /** push "มีเพื่อนรออยู่แล้ว" ถึงคนที่กำลังเดินทางมาจุดเดียวกัน */
    private function notifyHeadingTo(Booking $arrivedBooking, SchedulePickupPoint $point): void
    {
        $schedule = $arrivedBooking->schedule;
        $validIds = $this->validIds($schedule);
        $label = $this->arrivals->label($point);

        $who = $this->entryName($arrivedBooking, $this->passengersAt($arrivedBooking, $point, $validIds));
        $body = "{$who} ถึง{$label}แล้ว";

        if ($note = $this->freshNote($arrivedBooking)) {
            $body .= " · {$note}";
        }

        // คนที่เพิ่งถึงเองไม่ต้องได้ข่าวว่าตัวเองถึงแล้ว (เผื่อเป็นเพื่อนในใบจองอื่นด้วย)
        $sent = array_fill_keys($this->arrivals->recipientsOf($arrivedBooking), true);

        foreach ($this->arrivals->bookingsWaitingAt($schedule, $point) as $other) {
            if ((int) $other->id === (int) $arrivedBooking->id) {
                continue;
            }

            if (! in_array($other->freshPickupStatus(), self::MOVING_STATUSES, true)) {
                continue;
            }

            foreach ($this->arrivals->recipientsOf($other) as $userId) {
                if (isset($sent[$userId])) {
                    continue;
                }
                $sent[$userId] = true;

                SmartNotification::send(
                    $userId,
                    'pickup_mate_arrived',
                    'มีเพื่อนรออยู่ที่จุดรับแล้ว',
                    $body,
                    [
                        'booking_ref' => $other->booking_ref,
                        'schedule_id' => $schedule->id,
                        'pickup_point_id' => $point->id,
                        'route' => 'trip_day',
                    ],
                );
            }
        }
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @param  array<int, User>  $avatars
     * @param  array<int, int>  $validIds
     * @return array<string, mixed>
     */
    private function presentPoint(
        SchedulePickupPoint $point,
        Booking $mine,
        Collection $bookings,
        array $avatars,
        array $validIds,
    ): array {
        $me = null;
        $mates = [];

        foreach ($bookings as $booking) {
            $isMine = (int) $booking->id === (int) $mine->id;
            $candidate = $isMine ? $mine : $booking;

            if (! $isMine && $candidate->pickup_mates_hidden) {
                continue;
            }

            $passengers = $this->passengersAt($candidate, $point, $validIds);

            if ($passengers === null) {
                continue;
            }

            $entry = $this->presentEntry($candidate, $passengers, $avatars, $isMine);

            if ($isMine) {
                $me = $entry;
            } else {
                $mates[] = $entry;
            }
        }

        usort($mates, fn (array $a, array $b) => [$this->statusRank($a['status']), $a['status_at'] ?? '9']
            <=> [$this->statusRank($b['status']), $b['status_at'] ?? '9']);

        $vanArrivedAt = $this->vanArrivedAt($point);

        return [
            'id' => $point->id,
            'label' => $this->arrivals->label($point),
            'pickup_time' => $point->pickup_time,
            // รูปและหมายเหตุของจุดรับที่แอดมินตั้งไว้ (คัดลอกไปรอบใหม่ให้เอง) —
            // "ยืนรอตรงนี้" ที่ดีที่สุดคือที่ที่ทุกคนเห็นรูปเดียวกัน
            'image_url' => $point->image_url,
            'notes' => $point->notes,
            'map_url' => $point->map_url,
            'van_arrived_at' => $vanArrivedAt?->toIso8601String(),
            'people' => array_sum(array_column($mates, 'people')),
            'arrived_people' => array_sum(array_map(
                fn (array $m) => $m['status'] === Booking::PICKUP_STATUS_ARRIVED ? $m['people'] : 0,
                $mates,
            )),
            'me' => $me,
            'mates' => $mates,
        ];
    }

    /**
     * @param  Collection<int, BookingPassenger>  $passengers
     * @param  array<int, User>  $avatars
     * @return array<string, mixed>
     */
    private function presentEntry(Booking $booking, Collection $passengers, array $avatars, bool $isMine): array
    {
        $status = $booking->freshPickupStatus();
        $eta = $status === Booking::PICKUP_STATUS_LATE ? $booking->pickup_status_eta_minutes : null;

        return [
            'key' => 'b'.$booking->id,
            'is_mine' => $isMine,
            'name' => $this->entryName($booking, $passengers),
            'people' => max(1, $passengers->count()),
            'status' => $status,
            'status_label' => PickupStatusService::label($status, $eta),
            'status_at' => $status ? $booking->pickup_status_at?->toIso8601String() : null,
            'eta_minutes' => $eta,
            'note' => $this->freshNote($booking),
            'avatar_url' => $this->avatarFor($booking, $passengers, $avatars),
        ];
    }

    /**
     * ผู้โดยสารของใบจองที่ยังรอขึ้นรถอยู่ที่จุดนี้ — null เมื่อไม่มีใครเลย
     * (คอลเลกชันว่าง = ใบจองเก่าที่ไม่มีรายชื่อแยก ถือเป็นหนึ่งคน)
     *
     * @param  array<int, int>  $validIds
     * @return Collection<int, BookingPassenger>|null
     */
    private function passengersAt(Booking $booking, SchedulePickupPoint $point, array $validIds): ?Collection
    {
        if ($booking->passengers->isEmpty()) {
            return in_array((int) $point->id, $this->arrivals->effectivePickupPointIds($booking, $validIds, awaitingOnly: true), true)
                ? collect()
                : null;
        }

        $at = $booking->passengers
            ->filter(fn (BookingPassenger $p) => $p->isAwaitingBoarding()
                && $this->arrivals->passengerPointId($booking, $p, $validIds) === (int) $point->id)
            ->values();

        return $at->isEmpty() ? null : $at;
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @param  array<int, int>  $validIds
     * @return array{0: int, 1: int} [คนทั้งหมดที่จุดนี้, คนที่แจ้งว่าถึงแล้ว] ไม่นับคนที่ซ่อนตัว
     */
    private function countAt(SchedulePickupPoint $point, Collection $bookings, array $validIds): array
    {
        $people = 0;
        $arrived = 0;

        foreach ($bookings as $booking) {
            if ($booking->pickup_mates_hidden) {
                continue;
            }

            $passengers = $this->passengersAt($booking, $point, $validIds);

            if ($passengers === null) {
                continue;
            }

            $count = max(1, $passengers->count());
            $people += $count;

            if ($booking->freshPickupStatus() === Booking::PICKUP_STATUS_ARRIVED) {
                $arrived += $count;
            }
        }

        return [$people, $arrived];
    }

    /**
     * "นุ่น, เอ +1" — ชื่อเล่นก่อน (คนไทยเรียกกันด้วยชื่อเล่น) ไม่งั้นชื่อแบบย่อ
     *
     * @param  Collection<int, BookingPassenger>  $passengers
     */
    private function entryName(Booking $booking, ?Collection $passengers): string
    {
        if (! $passengers || $passengers->isEmpty()) {
            $booking->loadMissing('user:id,name,nickname,avatar');

            return trim((string) $booking->user?->nickname) ?: MaskedName::of($booking->user?->name, 'เพื่อนร่วมทริป');
        }

        $names = $passengers
            ->map(fn (BookingPassenger $p) => trim((string) $p->nickname) ?: MaskedName::of($p->name, 'เพื่อนร่วมทริป'))
            ->values();

        $shown = $names->take(self::NAMES_SHOWN)->implode(', ');
        $rest = $names->count() - self::NAMES_SHOWN;

        return $rest > 0 ? "{$shown} +{$rest}" : $shown;
    }

    /**
     * รูปโปรไฟล์ — ช่วยให้จำหน้ากันได้ตอนมืด ๆ แต่มีเฉพาะตอนรู้แน่ว่าเป็นใคร:
     * ผู้โดยสารคนเดียวที่จุดนี้ และผูกกับบัญชีที่ตั้งรูปเองไว้จริง (ไม่ใช่รูปตัวอักษร)
     *
     * @param  Collection<int, BookingPassenger>  $passengers
     * @param  array<int, User>  $avatars  passenger id => บัญชีของเพื่อนที่ผูกที่นั่งนั้น
     */
    private function avatarFor(Booking $booking, Collection $passengers, array $avatars): ?string
    {
        $user = null;

        if ($passengers->count() === 1) {
            $passenger = $passengers->first();
            $user = $avatars[(int) $passenger->id] ?? null;

            if (! $user && $booking->passengers->count() === 1) {
                $user = $booking->user;
            }
        } elseif ($passengers->isEmpty()) {
            $user = $booking->user;
        }

        return $user && filled($user->avatar) ? $user->avatar_url : null;
    }

    /**
     * ใบจองที่ยังมีคนรอขึ้นรถของรอบนี้ พร้อมของที่ต้องใช้แสดงผล
     *
     * @return Collection<int, Booking>
     */
    private function roundBookings(TripSchedule $schedule): Collection
    {
        return Booking::with(['passengers:'.self::PASSENGER_COLUMNS, 'user:id,name,nickname,avatar'])
            ->where('schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->where('is_join_trip', false)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return array<int, User> passenger id => user
     */
    private function avatarUsers(Collection $bookings): array
    {
        $members = BookingMember::with('user:id,name,nickname,avatar')
            ->whereIn('booking_id', $bookings->pluck('id'))
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->whereNotNull('passenger_id')
            ->whereNotNull('user_id')
            ->get();

        $map = [];
        foreach ($members as $member) {
            if ($member->user) {
                $map[(int) $member->passenger_id] = $member->user;
            }
        }

        return $map;
    }

    private function freshNote(Booking $booking): ?string
    {
        if (! filled($booking->pickup_note) || ! $booking->pickup_note_at) {
            return null;
        }

        return $booking->pickup_note_at->greaterThan(now()->subHours(Booking::PICKUP_STATUS_TTL_HOURS))
            ? $booking->pickup_note
            : null;
    }

    private function vanArrivedAt(SchedulePickupPoint $point): ?CarbonInterface
    {
        $at = $point->arrived_at;

        return $at && $at->gte(now()->subMinutes(PickupArrivalService::TRUST_MINUTES)) ? $at : null;
    }

    private function statusRank(?string $status): int
    {
        return match ($status) {
            Booking::PICKUP_STATUS_ARRIVED => 0,
            Booking::PICKUP_STATUS_ON_THE_WAY => 1,
            Booking::PICKUP_STATUS_LATE => 2,
            default => 3,
        };
    }

    /** @return array<int, int> */
    private function validIds(TripSchedule $schedule): array
    {
        return $schedule->pickupPoints->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function today(): string
    {
        return now(TripSchedule::REVIEW_AVAILABLE_TIMEZONE)->toDateString();
    }
}
