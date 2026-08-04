<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * ข้อความ "มีอะไรเกิดขึ้นกับรอบนี้" ที่เด้งเข้าห้องแชทเอง
 *
 * ต่างจาก TripChatTimelineService ตรงที่อันนั้นยิงตามเวลา (นับถอยหลัง เตรียมของ
 * อากาศ ฯลฯ) ส่วนอันนี้ยิงตาม "เหตุการณ์" ของรอบ — มีเพื่อนร่วมทริปคนใหม่เข้ามา,
 * ทีมงานประจำรอบมาแล้ว, รถ/คนขับพร้อม, รอบการันตีออกเดินทาง, รอบเต็ม
 *
 * หลักการเดียวกับไทม์ไลน์
 * - ทุกข้อความมี `system_key` (unique ต่อห้องระดับ DB) → เรียกซ้ำไม่ทำให้ข้อความซ้ำ
 * - best-effort ทั้งหมด: ห้ามให้ความผิดพลาดของแชทไปล้มการจอง/การบันทึกของแอดมิน
 * - ไม่ยิง push — ทุกเหตุการณ์ในนี้มี noti ของตัวเองอยู่แล้ว (SmartNotification /
 *   broadcast) ข้อความในห้องทำหน้าที่เป็นบันทึกของรอบ + badge ยังไม่อ่าน
 * - รอบที่ยกเลิกหรือออกเดินทางไปแล้วไม่โพสต์ (แอดมินแก้ข้อมูลย้อนหลังได้)
 */
class ChatRoomEventService
{
    public function __construct(private ChatService $chatService) {}

    /**
     * มีเพื่อนร่วมทริปคนใหม่เข้ารอบ (การจองถูกยืนยันแล้ว)
     *
     * คนแรกของรอบไม่ประกาศ — ยังไม่มีใครในห้องให้บอก และการเห็นชื่อตัวเองเด้ง
     * ขึ้นมาต้อนรับตัวเองก็แปลก ๆ (ข้อความต้อนรับของห้องทำหน้าที่นั้นอยู่แล้ว)
     */
    public function memberJoined(Booking $booking): void
    {
        $schedule = $booking->schedule;
        if (! $schedule) {
            return;
        }

        $otherBookingIds = Booking::where('schedule_id', $schedule->id)
            ->whereIn('status', ['pending', 'confirmed', 'completed'])
            ->where('id', '!=', $booking->id)
            ->pluck('id');

        if ($otherBookingIds->isEmpty()) {
            return;
        }

        $seats = max(1, $booking->passengers()->count());
        $name = $this->displayName($booking->user);
        $travellers = $seats + BookingPassenger::whereIn('booking_id', $otherBookingIds)->count();

        $this->post(
            $schedule,
            "🎉 {$this->joinerLabel($name, $seats)}เข้าร่วมทริปแล้วครับ\n"
                ."ตอนนี้เรามีเพื่อนร่วมทริป {$travellers} คนแล้ว ทักทายกันได้เลยครับ 🌿",
            "member_joined:{$booking->id}",
        );
    }

    /**
     * ทีมงานประจำรอบคนใหม่ถูก assign เข้ามา
     */
    public function staffAssigned(TripSchedule $schedule, User $staff): void
    {
        $name = $this->displayName($staff);

        $this->post(
            $schedule,
            "🎽 {$name} จะเป็นทีมงานดูแลรอบนี้ครับ\n"
                .'มีอะไรอยากถามก่อนเดินทาง ทักในห้องนี้ได้เลย ทีมงานอ่านทุกข้อความครับ',
            "staff_joined:{$staff->id}",
        );
    }

    /**
     * รถและคนขับของรอบพร้อมแล้ว — คีย์ผูกกับคันรถ เปลี่ยนรถจริงจึงแจ้งใหม่ได้
     */
    public function vehicleReady(TripSchedule $schedule): void
    {
        $vehicle = $schedule->vehicle;
        if (! $vehicle) {
            return;
        }

        $plate = trim((string) $vehicle->license_plate);
        $driver = trim((string) $vehicle->driver_name);

        $detail = collect([
            $plate !== '' ? "ทะเบียน {$plate}" : null,
            $driver !== '' ? "คนขับ {$driver}" : null,
        ])->filter()->implode(' · ');

        if ($detail === '') {
            return;
        }

        $this->post(
            $schedule,
            "🚐 รถและคนขับของรอบนี้พร้อมแล้วครับ — {$detail}\n"
                .'ดูรายละเอียดและกดโทรหาคนขับได้ที่ใบจองในแอปครับ',
            "vehicle_ready:{$vehicle->id}",
        );
    }

    /**
     * รอบนี้มีผู้ร่วมทริปครบเกณฑ์การันตีออกเดินทางแล้ว
     */
    public function departureGuaranteed(TripSchedule $schedule): void
    {
        $this->post(
            $schedule,
            "✅ รอบนี้การันตีออกเดินทางแล้วครับ!\n"
                .'มีเพื่อนร่วมทริปครบตามเกณฑ์ รถออกแน่นอน ไม่ต้องลุ้นแล้วครับ 🎉',
            'guaranteed',
        );
    }

    /**
     * รอบนี้ถูกจองเต็มทุกที่นั่ง
     */
    public function soldOut(TripSchedule $schedule): void
    {
        $this->post(
            $schedule,
            "🔥 รอบนี้เต็มทุกที่นั่งแล้วครับ ครบทีมพอดี\n"
                .'ใครชวนเพื่อนไว้ ให้เพื่อนกดเข้าคิวรอที่นั่งในแอปได้เลย ถ้ามีคนสละสิทธิ์ระบบจะแจ้งให้อัตโนมัติครับ',
            'sold_out',
        );
    }

    /**
     * โพสต์ข้อความระบบแบบกันซ้ำ + กันรอบที่ไม่ควรโพสต์ (ยกเลิก / เดินทางไปแล้ว)
     */
    private function post(TripSchedule $schedule, string $body, string $systemKey): void
    {
        try {
            if ($schedule->status === 'cancelled') {
                return;
            }

            $departsAt = $schedule->effectiveDepartsAt();
            if ($departsAt && $departsAt->isPast()) {
                return;
            }

            $exists = ChatMessage::where('schedule_id', $schedule->id)
                ->where('system_key', $systemKey)
                ->exists();

            if ($exists) {
                return;
            }

            // ห้องต้องเริ่มด้วยข้อความต้อนรับเสมอ ไม่ใช่ข้อความเหตุการณ์
            $this->chatService->ensureWelcome($schedule);
            $this->chatService->postSystem($schedule, $body, $systemKey);
        } catch (\Throwable $e) {
            // แข่งกันโพสต์พร้อมกันจะชน unique key — ถือว่ามีข้อความนั้นแล้ว
            Log::warning('ChatRoomEvent: โพสต์ข้อความเหตุการณ์ไม่สำเร็จ', [
                'schedule_id' => $schedule->id,
                'system_key' => $systemKey,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * "คุณเบส และเพื่อนอีก 2 คน " — เว้นวรรคท้ายไว้ต่อกับกริยา
     */
    private function joinerLabel(string $name, int $seats): string
    {
        return $seats > 1
            ? "{$name} และเพื่อนอีก ".($seats - 1).' คน '
            : "{$name} ";
    }

    /**
     * ชื่อที่ใช้เรียกในห้อง — ชื่อเล่นก่อน ไม่มีค่อยใช้ชื่อจริงคำแรก (กันชื่อ-สกุลเต็ม)
     */
    private function displayName(?User $user): string
    {
        $nickname = trim((string) $user?->nickname);
        if ($nickname !== '') {
            return $nickname;
        }

        $name = trim((string) $user?->name);
        if ($name === '') {
            return 'เพื่อนร่วมทริป';
        }

        return explode(' ', $name)[0];
    }
}
