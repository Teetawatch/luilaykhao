<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\SchedulePickupPoint;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * "รถจอดอยู่ตรงไหน" — คำตอบที่ลูกค้าต้องการตอนยืนอยู่ในลานจอดที่มีรถสิบคัน
 *
 * ทะเบียนรถอยู่ในใบจองมาตลอด แต่การอ่านทะเบียนจากหน้าจอแล้วไล่ดูท้ายรถทีละคัน
 * ไม่ใช่คำตอบ คนที่รู้จริงว่าจอดตรงไหนคือสตาฟที่นั่งมากับรถ ที่นี่จึงรับสิ่งที่
 * สตาฟกดได้ในสามวินาที (ถึงแล้ว + รูปตรงที่จอด) แล้วกระจายออกไปสองทางพร้อมกัน:
 * push ถึงคนที่รอจุดนั้นจริง ๆ และรูปลงห้องแชทของรอบให้ทุกคนเห็นย้อนหลังได้
 *
 * แยกจาก completed_at ("รับครบแล้ว") โดยตั้งใจ — สองนาทีนี้คนละเรื่องกัน และ
 * นาทีที่ลูกค้าต้องการคำตอบคือนาทีที่รถเพิ่งจอด ยังไม่มีใครขึ้นรถสักคน
 */
class PickupArrivalService
{
    /** โฟลเดอร์ของรูปจุดจอดบน disk สื่อสาธารณะ */
    public const PHOTO_DIR = 'pickups';

    /**
     * "สตาฟกดว่ารถถึงแล้ว" ใช้ตอบว่า "รถอยู่ตรงนั้นตอนนี้" ได้นานแค่ไหน (นาที)
     *
     * ทริปหลายวันกดขาไปครั้งหนึ่ง ถ้าไม่มีอายุ การ์ดของวันกลับจะยังบอกว่ารถจอด
     * รออยู่ที่จุดรับตั้งแต่เมื่อวาน
     */
    public const TRUST_MINUTES = 180;

    public function __construct(private ChatService $chatService) {}

    /**
     * สตาฟกดว่ารถถึงจุดนี้แล้ว — idempotent: กดซ้ำได้ แต่แจ้งเตือนครั้งเดียว
     *
     * @return array{notified: int, first_time: bool}
     */
    public function markArrived(
        TripSchedule $schedule,
        SchedulePickupPoint $point,
        User $staff,
        ?UploadedFile $photo = null,
        ?string $note = null,
    ): array {
        $firstTime = $point->arrived_at === null;
        $oldPhoto = $point->arrival_photo_path;

        $point->fill([
            'arrived_at' => $point->arrived_at ?? now(),
            'arrival_note' => $note !== null && trim($note) !== '' ? trim($note) : $point->arrival_note,
            'arrived_by_id' => $point->arrived_by_id ?? $staff->id,
        ]);

        if ($photo) {
            $point->arrival_photo_path = $photo->store(
                self::PHOTO_DIR.'/'.date('Y/m'),
                MediaDisk::name(),
            );
        }

        $point->save();

        // ถ่ายรูปใหม่ทับของเดิม (มุมแรกมืดไป/จอดใหม่) — ของเก่าไม่มีใครอ้างถึงแล้ว
        if ($photo && $oldPhoto && $oldPhoto !== $point->arrival_photo_path) {
            $this->deletePhoto($oldPhoto);
        }

        $this->postToChat($schedule, $point);

        $notified = $firstTime ? $this->notifyWaiting($schedule, $point) : 0;

        return ['notified' => $notified, 'first_time' => $firstTime];
    }

    /** กดผิดจุด — ถอนคืนให้หมด รวมรูปและข้อความในห้องแชท */
    public function clearArrival(TripSchedule $schedule, SchedulePickupPoint $point): void
    {
        $this->deletePhoto($point->arrival_photo_path);

        ChatMessage::where('schedule_id', $schedule->id)
            ->where('system_key', $this->chatKey($point))
            ->delete();

        $point->update([
            'arrived_at' => null,
            'arrival_photo_path' => null,
            'arrival_note' => null,
            'arrived_by_id' => null,
        ]);
    }

    /**
     * สภาพของจุดรับหนึ่งจุดสำหรับส่งออก API — ใช้ทั้งฝั่งสตาฟและฝั่งลูกค้า
     *
     * @return array<string, mixed>
     */
    public function present(SchedulePickupPoint $point): array
    {
        return [
            'id' => $point->id,
            'label' => $this->label($point),
            'pickup_time' => $point->pickup_time,
            'sort_order' => $point->sort_order,
            'arrived_at' => $point->arrived_at?->toIso8601String(),
            'arrival_note' => $point->arrival_note,
            'arrival_photo_url' => $point->arrival_photo_url,
            'completed_at' => $point->completed_at?->toIso8601String(),
        ];
    }

    /**
     * จุดรับของใบจองนี้ เมื่อสตาฟเพิ่งกดว่ารถถึงจุดนั้นจริง — ไม่งั้นคืน null
     *
     * ตรวจสองอย่างที่พลาดง่ายและให้ข้อมูลผิดทั้งคู่: จุดต้องเป็นของรอบนี้ (ใบจอง
     * ที่เคยถูกย้ายรอบมี FK ค้างชี้จุดของรอบเดิมได้ ซึ่งจะพารูปจุดจอดของทริปอื่น
     * มาแสดง) และต้องเพิ่งเกิดขึ้น ไม่ใช่ค่าที่ค้างจากขาไปของทริปหลายวัน
     */
    public function freshArrivalFor(Booking $booking): ?SchedulePickupPoint
    {
        $point = $booking->pickupPoint;

        if (! $point || (int) $point->schedule_id !== (int) $booking->schedule_id) {
            return null;
        }

        if (! $point->arrived_at || $point->arrived_at->lt(now()->subMinutes(self::TRUST_MINUTES))) {
            return null;
        }

        return $point;
    }

    /** ชื่อจุดที่เอาไปพูดกับลูกค้าได้ */
    public function label(SchedulePickupPoint $point): string
    {
        return trim((string) ($point->pickup_location ?: $point->region_label)) ?: 'จุดรับ';
    }

    /**
     * การจองที่ยังมีคนรออยู่ที่จุดนี้ (ยังไม่ขึ้นรถ และไม่ได้แจ้งว่าไม่ไป)
     *
     * ดูรายคน ไม่ใช่ระดับใบจอง — ใบจองเดียวกันขึ้นคนละจุดได้ คนจองเช็คอินที่จุดแรก
     * แล้วเพื่อนที่ยืนรอจุดถัดไปก็ยังต้องได้รู้ว่ารถมาถึงแล้ว
     *
     * @return Collection<int, Booking>
     */
    public function bookingsWaitingAt(TripSchedule $schedule, SchedulePickupPoint $point): Collection
    {
        $validIds = $schedule->pickupPoints->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $this->pendingBookings($schedule)
            ->filter(fn (Booking $b) => in_array(
                (int) $point->id,
                $this->effectivePickupPointIds($b, $validIds, awaitingOnly: true),
                true,
            ))
            ->values();
    }

    /**
     * ยังมีคนรออยู่จุดละกี่คน — สตาฟใช้ดูว่าจุดไหนยังต้องแวะ และใช้ตัดสินว่าจุดไหน
     * "รับครบแล้ว"
     *
     * นับหัวคนแบบรายผู้โดยสาร เพราะคนในใบจองเดียวกันเลือกจุดรับคนละจุดได้ และ
     * ขึ้นรถไม่พร้อมกันได้
     *
     * @return array<int, int> pointId => จำนวนคน
     */
    public function waitingCounts(TripSchedule $schedule): array
    {
        $validIds = $schedule->pickupPoints->pluck('id')->map(fn ($id) => (int) $id)->all();

        $counts = [];

        foreach ($this->pendingBookings($schedule) as $booking) {
            if ($this->hasCustomPickup($booking)) {
                continue;
            }

            // ใบจองเก่าที่ไม่มีรายชื่อผู้โดยสารแยก นับเป็นหนึ่งหัวที่จุดของใบจอง
            if ($booking->passengers->isEmpty()) {
                foreach ($this->effectivePickupPointIds($booking, $validIds) as $pointId) {
                    $counts[$pointId] = ($counts[$pointId] ?? 0) + 1;
                }

                continue;
            }

            // นับรายคนจริง ๆ ไม่ใช่หารจำนวนคนด้วยจำนวนจุด — ใบจอง 3 คนที่แยกขึ้น
            // สองจุด (2 คนจุดแรก 1 คนจุดหลัง) เคยถูกนับเป็นจุดละ 2 รวมเป็น 4 หัว
            foreach ($booking->passengers as $passenger) {
                if (! $passenger->isAwaitingBoarding()) {
                    continue;
                }

                $pointId = $this->passengerPointId($booking, $passenger, $validIds);

                if ($pointId !== null) {
                    $counts[$pointId] = ($counts[$pointId] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * จุดรับทั้งหมดที่ผู้โดยสารของการจองนี้ยืนรออยู่จริง
     *
     * จุดรายคนมาก่อนจุดระดับการจอง, จุดที่ชี้ข้ามรอบ (FK ค้างจากตอนย้ายรอบ) ถือว่า
     * ใช้ไม่ได้แล้วให้ตกกลับไปจุดของการจอง และการจองที่ปักหมุดเองไม่นับเข้าจุด
     * ตายตัวใด ๆ — กติกาเดียวกับที่ manifest ใช้จัดกลุ่ม
     *
     * $awaitingOnly = นับเฉพาะคนที่ยังรอขึ้นรถ, $onlyPassengerIds = นับเฉพาะคนเหล่านี้
     * (เช่น คนที่เพิ่งเช็คอิน — จุดที่ "เพิ่งมีคนขึ้น") ใบจองที่ไม่มีรายชื่อแยกใช้จุด
     * ระดับใบจอง และถือว่ารออยู่ตราบที่ใบยังไม่เช็คอิน
     *
     * @param  array<int, int>  $validIds  id ของจุดรับที่อยู่ในรอบนี้จริง
     * @param  array<int, int>|null  $onlyPassengerIds
     * @return array<int, int>
     */
    public function effectivePickupPointIds(
        Booking $booking,
        array $validIds,
        bool $awaitingOnly = false,
        ?array $onlyPassengerIds = null,
    ): array {
        if ($this->hasCustomPickup($booking)) {
            return [];
        }

        $bookingPointId = in_array((int) $booking->pickup_point_id, $validIds, true)
            ? (int) $booking->pickup_point_id
            : null;

        // การจองเก่าที่ไม่มีรายชื่อผู้โดยสารแยก — ใช้จุดระดับการจองแทน
        if ($booking->passengers->isEmpty()) {
            if ($bookingPointId === null || ($awaitingOnly && $booking->checked_in)) {
                return [];
            }

            return [$bookingPointId];
        }

        $ids = [];

        foreach ($booking->passengers as $passenger) {
            if ($awaitingOnly && ! $passenger->isAwaitingBoarding()) {
                continue;
            }

            if ($onlyPassengerIds !== null && ! in_array((int) $passenger->id, $onlyPassengerIds, true)) {
                continue;
            }

            $resolved = $this->passengerPointId($booking, $passenger, $validIds);

            if ($resolved) {
                $ids[$resolved] = true;
            }
        }

        return array_keys($ids);
    }

    /** จุดรับของผู้โดยสารคนหนึ่ง: จุดของตัวเองก่อน แล้วค่อยจุดของใบจอง */
    public function passengerPointId(Booking $booking, BookingPassenger $passenger, array $validIds): ?int
    {
        if ($this->hasCustomPickup($booking)) {
            return null;
        }

        $own = in_array((int) $passenger->pickup_point_id, $validIds, true)
            ? (int) $passenger->pickup_point_id
            : null;

        $bookingPointId = in_array((int) $booking->pickup_point_id, $validIds, true)
            ? (int) $booking->pickup_point_id
            : null;

        return $own ?? $bookingPointId;
    }

    /** ใบจองที่ปักหมุดจุดรับเอง — ไม่อยู่ในจุดรับตายตัวจุดใด */
    public function hasCustomPickup(Booking $booking): bool
    {
        return ! $booking->pickup_point_id
            && $booking->custom_pickup_lat !== null
            && $booking->custom_pickup_lng !== null
            && $booking->custom_pickup_status !== 'rejected';
    }

    /**
     * ใบจองที่ยืนยันแล้วของรอบนี้ ที่ยังมีใครสักคนรอขึ้นรถอยู่
     *
     * @return Collection<int, Booking>
     */
    private function pendingBookings(TripSchedule $schedule): Collection
    {
        return Booking::with('passengers:id,booking_id,pickup_point_id,checked_in_at,not_going_at')
            ->where('schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->where(fn ($q) => $q
                ->where('checked_in', false)
                ->orWhereHas('passengers', fn ($p) => $p
                    ->whereNull('checked_in_at')
                    ->whereNull('not_going_at')))
            ->get();
    }

    /** ข้อความที่ลูกค้าได้รับ — ทะเบียนมาก่อนเสมอ เพราะนั่นคือสิ่งที่เขากำลังมองหา */
    public function pushBody(TripSchedule $schedule, SchedulePickupPoint $point): string
    {
        $vehicle = $schedule->vehicle;
        $plate = trim((string) $vehicle?->license_plate);
        $colour = trim((string) $vehicle?->color);

        $what = $plate !== '' ? "ทะเบียน {$plate}" : 'รถของทริป';
        if ($colour !== '') {
            $what .= " ({$colour})";
        }

        $body = "{$what} จอดรออยู่ที่".$this->label($point).'แล้ว';

        if ($point->arrival_note) {
            $body .= ' · '.$point->arrival_note;
        }

        return $body;
    }

    /** @return int จำนวนคนที่ได้รับแจ้ง */
    private function notifyWaiting(TripSchedule $schedule, SchedulePickupPoint $point): int
    {
        $body = $this->pushBody($schedule, $point);
        $notified = 0;

        foreach ($this->bookingsWaitingAt($schedule, $point) as $booking) {
            foreach ($this->recipientsOf($booking) as $userId) {
                SmartNotification::send(
                    $userId,
                    'pickup_arrived',
                    'รถถึงจุดรับแล้ว 🚐',
                    $body,
                    [
                        'booking_ref' => $booking->booking_ref,
                        'schedule_id' => $schedule->id,
                        'pickup_point_id' => $point->id,
                        'route' => 'booking',
                    ],
                );
                $notified++;
            }
        }

        return $notified;
    }

    /**
     * เจ้าของใบจอง + เพื่อนที่ถูกเชิญ — เพื่อนที่ไปด้วยกันก็ยืนรออยู่ตรงนั้นเหมือนกัน
     *
     * @return array<int, int>
     */
    private function recipientsOf(Booking $booking): array
    {
        $ids = $booking->user_id ? [(int) $booking->user_id] : [];

        $members = BookingMember::where('booking_id', $booking->id)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([...$ids, ...$members]));
    }

    /**
     * ลงห้องแชทของรอบ — คนที่ปิดการแจ้งเตือนไว้ หรือเปิดแอปช้าไปห้านาที ยังเห็น
     * รูปจุดจอดย้อนหลังได้ system_key กันไม่ให้โพสต์ซ้ำเวลาสตาฟกดหลายครั้ง
     */
    private function postToChat(TripSchedule $schedule, SchedulePickupPoint $point): void
    {
        $key = $this->chatKey($point);
        $body = $this->chatBody($schedule, $point);

        $existing = ChatMessage::where('schedule_id', $schedule->id)
            ->where('system_key', $key)
            ->first();

        if ($existing) {
            $this->updateChatMessage($existing, $body, $point);

            return;
        }

        $this->chatService->ensureWelcome($schedule);

        try {
            $this->chatService->postSystem($schedule, $body, $key, $point->arrival_photo_path);
        } catch (QueryException $e) {
            // สตาฟสองคนบนรถคันเดียวกันกดพร้อมกัน — system_key เป็น unique ระดับ DB
            // คนที่แพ้จังหวะต้องได้ผลเหมือนกดทีหลัง ไม่ใช่ 500 กลางลานจอด
            $raced = ChatMessage::where('schedule_id', $schedule->id)
                ->where('system_key', $key)
                ->first();

            if (! $raced) {
                throw $e;
            }

            $this->updateChatMessage($raced, $body, $point);
        }
    }

    /** สตาฟถ่ายรูปตามมาทีหลัง — เติมรูปให้ข้อความเดิมแทนการโพสต์ใหม่ */
    private function updateChatMessage(ChatMessage $message, string $body, SchedulePickupPoint $point): void
    {
        $message->update([
            'body' => $body,
            'image_path' => $point->arrival_photo_path ?? $message->image_path,
        ]);
    }

    private function chatKey(SchedulePickupPoint $point): string
    {
        return "pickup_arrived_{$point->id}";
    }

    private function chatBody(TripSchedule $schedule, SchedulePickupPoint $point): string
    {
        $lines = ['🚐 รถถึง'.$this->label($point).'แล้วครับ'];

        $vehicle = $schedule->vehicle;
        $plate = trim((string) $vehicle?->license_plate);
        $colour = trim((string) $vehicle?->color);
        $name = trim((string) $vehicle?->name);

        $describe = trim($name.($colour !== '' ? " สี{$colour}" : ''));
        if ($plate !== '') {
            $lines[] = trim(($describe !== '' ? "{$describe} · " : '')."ทะเบียน {$plate}");
        } elseif ($describe !== '') {
            $lines[] = $describe;
        }

        if ($point->arrival_note) {
            $lines[] = $point->arrival_note;
        }

        $lines[] = $point->arrival_photo_path
            ? 'รูปนี้คือตรงที่รถจอดครับ เดินมาได้เลย 🙏'
            : 'เดินมาที่จุดรับได้เลยครับ 🙏';

        return implode("\n", $lines);
    }

    private function deletePhoto(?string $path): void
    {
        if (! $path || str_starts_with($path, 'http')) {
            return;
        }

        try {
            Storage::disk(MediaDisk::name())->delete($path);
        } catch (\Throwable $e) {
            // ไฟล์ค้างหนึ่งใบถูกกว่าการทำให้สตาฟกดถอนไม่ได้หน้างาน
        }
    }
}
