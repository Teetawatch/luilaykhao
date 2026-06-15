<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\ChatReaction;
use App\Models\ChatRead;
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

        if ($purged > 0) {
            Log::info('PurgeEndedTripChatsJob completed', [
                'rooms_purged' => $purged,
                'images_deleted' => $imagesDeleted,
            ]);
        }
    }

    /**
     * Delete uploaded chat images for a schedule from the media disk. Legacy
     * rows that stored an absolute URL are skipped (no local path to remove).
     */
    private function deleteImages(int $scheduleId): int
    {
        $paths = ChatMessage::where('schedule_id', $scheduleId)
            ->whereNotNull('image_path')
            ->pluck('image_path')
            ->filter(fn ($p) => $p !== '' && ! str_starts_with($p, 'http'))
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
