<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\ChatReaction;
use App\Models\ChatRead;
use App\Models\SchedulePickupPoint;
use App\Models\TripSchedule;
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
            ChatMessage::where('schedule_id', $scheduleId)->delete();
            ChatRead::where('schedule_id', $scheduleId)->delete();

            $purged++;
        }

        if ($purged > 0 || $pickupPhotos > 0) {
            Log::info('PurgeEndedTripChatsJob completed', [
                'rooms_purged' => $purged,
                'images_deleted' => $imagesDeleted,
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
        $paths = ChatMessage::where('schedule_id', $scheduleId)
            ->whereNotNull('image_path')
            ->pluck('image_path')
            ->filter(fn ($p) => $p !== '' && str_starts_with((string) $p, 'chat/'))
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
