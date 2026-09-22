<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\ChatMessage;
use App\Models\SchedulePickupPoint;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
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

    /** ชื่อจุดที่เอาไปพูดกับลูกค้าได้ */
    public function label(SchedulePickupPoint $point): string
    {
        return trim((string) ($point->pickup_location ?: $point->region_label)) ?: 'จุดรับ';
    }

    /**
     * การจองที่ยังรออยู่ที่จุดนี้ (ยังไม่เช็คอิน)
     *
     * @return Collection<int, Booking>
     */
    public function bookingsWaitingAt(TripSchedule $schedule, SchedulePickupPoint $point): Collection
    {
        $validIds = $schedule->pickupPoints->pluck('id')->map(fn ($id) => (int) $id)->all();

        return Booking::with('passengers:id,booking_id,pickup_point_id')
            ->where('schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->where('checked_in', false)
            ->get()
            ->filter(fn (Booking $b) => in_array(
                (int) $point->id,
                $this->effectivePickupPointIds($b, $validIds),
                true,
            ))
            ->values();
    }

    /**
     * ยังมีคนรออยู่จุดละกี่คน — สตาฟใช้ดูว่าจุดไหนยังต้องแวะ
     *
     * นับหัวคนแบบรายผู้โดยสาร เพราะคนในใบจองเดียวกันเลือกจุดรับคนละจุดได้
     *
     * @return array<int, int> pointId => จำนวนคน
     */
    public function waitingCounts(TripSchedule $schedule): array
    {
        $validIds = $schedule->pickupPoints->pluck('id')->map(fn ($id) => (int) $id)->all();

        $bookings = Booking::with('passengers:id,booking_id,pickup_point_id')
            ->where('schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->where('checked_in', false)
            ->get();

        $counts = [];

        foreach ($bookings as $booking) {
            $points = $this->effectivePickupPointIds($booking, $validIds);
            if ($points === []) {
                continue;
            }

            // ใบจองเก่าที่ไม่มีรายชื่อผู้โดยสารแยก นับเป็นหนึ่งหัว ไม่ใช่ศูนย์
            $perPoint = max(1, (int) ceil($booking->passengers->count() / count($points)));

            foreach ($points as $pointId) {
                $counts[$pointId] = ($counts[$pointId] ?? 0) + $perPoint;
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
     * @param  array<int, int>  $validIds  id ของจุดรับที่อยู่ในรอบนี้จริง
     * @return array<int, int>
     */
    public function effectivePickupPointIds(Booking $booking, array $validIds): array
    {
        $hasCustomPickup = ! $booking->pickup_point_id
            && $booking->custom_pickup_lat !== null
            && $booking->custom_pickup_lng !== null
            && $booking->custom_pickup_status !== 'rejected';

        if ($hasCustomPickup) {
            return [];
        }

        $bookingPointId = in_array((int) $booking->pickup_point_id, $validIds, true)
            ? (int) $booking->pickup_point_id
            : null;

        $ids = [];

        foreach ($booking->passengers as $passenger) {
            $own = in_array((int) $passenger->pickup_point_id, $validIds, true)
                ? (int) $passenger->pickup_point_id
                : null;

            $resolved = $own ?? $bookingPointId;

            if ($resolved) {
                $ids[$resolved] = true;
            }
        }

        // การจองเก่าที่ไม่มีรายชื่อผู้โดยสารแยก — ใช้จุดระดับการจองแทน
        if (empty($ids) && $bookingPointId) {
            $ids[$bookingPointId] = true;
        }

        return array_keys($ids);
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
            // สตาฟถ่ายรูปตามมาทีหลัง — เติมรูปให้ข้อความเดิมแทนการโพสต์ใหม่
            $existing->update([
                'body' => $body,
                'image_path' => $point->arrival_photo_path ?? $existing->image_path,
            ]);

            return;
        }

        $this->chatService->ensureWelcome($schedule);
        $this->chatService->postSystem($schedule, $body, $key, $point->arrival_photo_path);
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
