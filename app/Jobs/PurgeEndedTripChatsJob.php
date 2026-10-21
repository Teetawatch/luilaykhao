<?php

namespace App\Jobs;

use App\Models\ChatCollection;
use App\Models\ChatDropoffRound;
use App\Models\ChatFoodRound;
use App\Models\ChatMessage;
use App\Models\ChatPoll;
use App\Models\ChatPorterRound;
use App\Models\ChatReaction;
use App\Models\ChatRead;
use App\Models\ChatRestStop;
use App\Models\ChatRoomPreference;
use App\Models\ChatStopRequest;
use App\Models\ChatSupplyRequest;
use App\Models\ChatTrailCheck;
use App\Models\ChatWellnessCheck;
use App\Models\LostItem;
use App\Models\SchedulePickupPoint;
use App\Models\TripSchedule;
use App\Services\ChatCollectionService;
use App\Services\ChatPrivateService;
use App\Services\OnsiteMoneyService;
use App\Support\MediaDisk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reclaims storage by deleting a trip's group chat — messages, reactions, read
 * markers and any uploaded images — 3 days after the trip wraps up. The trip end
 * date is `return_date`, falling back to `departure_date`. Runs daily; once a
 * schedule is purged it has no messages left, so it's skipped on later runs.
 *
 * ยังเก็บรูป "จุดที่รถจอด" ของรอบนั้นไปด้วย (schedule_pickup_points) เพราะเป็น
 * ของที่หมดประโยชน์พร้อมกันและอยู่ในรอบเดียวกัน — ลบไฟล์แล้วล้างคอลัมน์ให้ตรงกัน
 * เสมอ ไม่ใช่ลบไฟล์ทิ้งแล้วปล่อยให้แถวชี้ไปยังรูปที่ไม่มีอยู่แล้ว
 */
class PurgeEndedTripChatsJob implements ShouldQueue
{
    use Queueable;

    public const DELETE_AFTER_DAYS = 3;

    public const TIMEZONE = 'Asia/Bangkok';

    public int $tries = 1;

    public function handle(): void
    {
        $cutoff = now(self::TIMEZONE)->subDays(self::DELETE_AFTER_DAYS)->toDateString();

        // Schedules whose trip ended on/before the cutoff date.
        $endedScheduleIds = TripSchedule::query()
            ->where(function ($q) use ($cutoff) {
                $q->whereDate('return_date', '<=', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNull('return_date')
                            ->whereDate('departure_date', '<=', $cutoff);
                    });
            })
            ->pluck('id');

        if ($endedScheduleIds->isEmpty()) {
            return;
        }

        $pickupPhotos = $this->purgePickupArrivalPhotos($endedScheduleIds->all());

        // เธรดถามทีมงานแบบส่วนตัวหายไปพร้อมห้อง — เก็บเรื่องสุขภาพ/เงินไว้นานกว่าทริปไม่ได้
        // (ไล่จากรอบที่จบแล้วทั้งหมด ไม่ใช่เฉพาะรอบที่ยังมีข้อความในห้องรวม)
        $privateFiles = app(ChatPrivateService::class)->purge($endedScheduleIds->all());

        // Only the ones that still have a chat to purge.
        $scheduleIds = ChatMessage::whereIn('schedule_id', $endedScheduleIds)
            ->distinct()
            ->pluck('schedule_id');

        $purged = 0;
        $imagesDeleted = 0;

        foreach ($scheduleIds as $scheduleId) {
            $messageIds = ChatMessage::where('schedule_id', $scheduleId)->pluck('id');

            $imagesDeleted += $this->deleteImages($scheduleId);

            // Reactions cascade at the DB level, but delete explicitly so this
            // works regardless of FK enforcement.
            ChatReaction::whereIn('message_id', $messageIds)->delete();
            // การ์ดในห้อง (โพล/โหวต, รับออเดอร์อาหาร, จุดพัก) และคำขอแวะห้องน้ำ
            // หมดหน้าที่พร้อมห้อง — ลูกของมัน (คะแนน/ออเดอร์/รายชื่อขึ้นรถ) ตามไปทาง FK
            ChatPoll::where('schedule_id', $scheduleId)->delete();
            ChatFoodRound::where('schedule_id', $scheduleId)->delete();
            ChatRestStop::where('schedule_id', $scheduleId)->delete();
            // ยอดเงินหน้างานต้องอยู่ต่อจนการเงินตรวจรับ — แช่สรุปไว้ก่อนการ์ดหายไปกับห้อง
            if ($schedule = TripSchedule::find($scheduleId)) {
                app(OnsiteMoneyService::class)->freeze($schedule);
            }
            // สลิปเก็บเงินอยู่บนดิสก์ส่วนตัว ไม่ตามไปกับแถวที่ถูกลบ — ลบไฟล์ก่อน
            app(ChatCollectionService::class)->purgeSlips($scheduleId);
            // ลูกหาบผูกกับการ์ดเก็บเงิน — ลบก่อน (FK nullOnDelete อยู่แล้ว แต่ไม่พึ่ง)
            ChatPorterRound::where('schedule_id', $scheduleId)->delete();
            ChatTrailCheck::where('schedule_id', $scheduleId)->delete();
            ChatDropoffRound::where('schedule_id', $scheduleId)->delete();
            ChatWellnessCheck::where('schedule_id', $scheduleId)->delete();
            ChatCollection::where('schedule_id', $scheduleId)->delete();
            ChatStopRequest::where('schedule_id', $scheduleId)->delete();
            ChatSupplyRequest::where('schedule_id', $scheduleId)->delete();
            // ของหายต้องอยู่ต่อหลังห้องถูกลบ — แค่ตัดการ์ดในแชทออก
            LostItem::where('schedule_id', $scheduleId)->update(['message_id' => null]);
            ChatMessage::where('schedule_id', $scheduleId)->delete();
            ChatRead::where('schedule_id', $scheduleId)->delete();
            ChatRoomPreference::where('schedule_id', $scheduleId)->delete();

            $purged++;
        }

        if ($purged > 0 || $pickupPhotos > 0 || $privateFiles > 0) {
            Log::info('PurgeEndedTripChatsJob completed', [
                'rooms_purged' => $purged,
                'images_deleted' => $imagesDeleted,
                'private_files_deleted' => $privateFiles,
                'pickup_photos_deleted' => $pickupPhotos,
            ]);
        }
    }

    /**
     * ลบรูปจุดจอดของรอบที่จบไปแล้ว แล้วล้างคอลัมน์ให้ตรงกับไฟล์ที่ลบไป
     *
     * @param  array<int, int>  $scheduleIds
     */
    private function purgePickupArrivalPhotos(array $scheduleIds): int
    {
        $points = SchedulePickupPoint::whereIn('schedule_id', $scheduleIds)
            ->whereNotNull('arrival_photo_path')
            ->get(['id', 'arrival_photo_path']);

        if ($points->isEmpty()) {
            return 0;
        }

        $paths = $points
            ->pluck('arrival_photo_path')
            ->filter(fn ($p) => $p !== '' && ! str_starts_with((string) $p, 'http'))
            ->values();

        try {
            if ($paths->isNotEmpty()) {
                Storage::disk(MediaDisk::name())->delete($paths->all());
            }
        } catch (\Throwable $e) {
            Log::warning('PurgeEndedTripChatsJob pickup photo delete failed', [
                'error' => $e->getMessage(),
            ]);
        }

        SchedulePickupPoint::whereIn('id', $points->pluck('id'))
            ->update(['arrival_photo_path' => null]);

        return $points->count();
    }

    /**
     * Delete uploaded chat images for a schedule from the media disk. Legacy
     * rows that stored an absolute URL are skipped (no local path to remove).
     */
    private function deleteImages(int $scheduleId): int
    {
        // เฉพาะไฟล์ที่ห้องแชทเป็นเจ้าของจริง ๆ — ข้อความระบบ "รถถึงจุดรับแล้ว"
        // ใช้รูปใบเดียวกับที่ schedule_pickup_points ถืออยู่ ถ้าลบตรงนี้ด้วย แถว
        // ของจุดรับจะชี้ไปยังไฟล์ที่ไม่มีอยู่แล้ว (ลบที่เดียวใน purgePickupArrivalPhotos)
        // รูปและข้อความเสียงของห้อง (เสียงอยู่ใต้ chat/audio/ จึงผ่านเงื่อนไขเดียวกัน)
        $paths = ChatMessage::where('schedule_id', $scheduleId)
            ->where(fn ($q) => $q->whereNotNull('image_path')->orWhereNotNull('audio_path'))
            ->get(['image_path', 'audio_path'])
            ->flatMap(fn (ChatMessage $m) => [$m->image_path, $m->audio_path])
            ->filter(fn ($p) => $p !== null && $p !== '' && str_starts_with((string) $p, 'chat/'))
            ->values();

        if ($paths->isEmpty()) {
            return 0;
        }

        try {
            Storage::disk(MediaDisk::name())->delete($paths->all());
        } catch (\Throwable $e) {
            // Don't block the DB cleanup on a storage hiccup — orphaned files
            // are rare and far cheaper than never reclaiming the rows.
            Log::warning('PurgeEndedTripChatsJob image delete failed', [
                'schedule_id' => $scheduleId,
                'error' => $e->getMessage(),
            ]);
        }

        return $paths->count();
    }
}
