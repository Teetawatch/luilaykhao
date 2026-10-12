<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\BroadcastDispatch;
use App\Models\FcmToken;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fans a push broadcast out to its audience. Each recipient gets a
 * SmartNotification (DB row + FCM), so the message also lands in their in-app
 * inbox and badge, not just the push tray.
 *
 * Two callers:
 *  - [\App\Services\BroadcastNotificationService] for automatic marketing blasts
 *    (audience `all`), after it has already claimed the dedupe key.
 *  - the admin broadcast composer, which can also target one trip or one round.
 *
 * Audience rules differ on purpose: a marketing blast to everyone respects the
 * per-user `marketing_push_enabled` opt-out, while a message aimed at a single
 * round is operational ("พรุ่งนี้ฝนตก เตรียมเสื้อกันฝน") and must reach every
 * traveller on it regardless of their marketing preference.
 */
class SendBroadcastNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Users per SendBroadcastNotificationChunkJob — small enough to finish well inside the 60 s timeout. */
    public const CHUNK_SIZE = 50;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public array $data = [],
        public ?int $dispatchId = null,
        public string $audience = BroadcastDispatch::AUDIENCE_ALL,
        public ?int $audienceId = null,
    ) {}

    public function handle(): void
    {
        $sent = 0;

        // Only resolves the audience; the sending happens in small slices — see
        // SendBroadcastNotificationChunkJob for why one job can't do it all.
        $this->audienceQuery()
            ->select('users.id')
            ->chunkById(self::CHUNK_SIZE, function ($users) use (&$sent) {
                SendBroadcastNotificationChunkJob::dispatch(
                    $users->pluck('id')->all(),
                    $this->type,
                    $this->title,
                    $this->body,
                    $this->data,
                    $this->dispatchId,
                );
                $sent += $users->count();
            });

        if ($this->dispatchId) {
            BroadcastDispatch::whereKey($this->dispatchId)->update(['recipients_count' => $sent]);
        }

        Log::info('SendBroadcastNotificationJob fanned out', [
            'type' => $this->type,
            'audience' => $this->audience,
            'audience_id' => $this->audienceId,
            'recipients' => $sent,
        ]);
    }

    /**
     * Resolve the audience to a user query. Everyone targeted must have at
     * least one active FCM token — a user with no device can't be reached.
     */
    private function audienceQuery(): Builder
    {
        $query = User::query()
            ->whereIn('id', FcmToken::where('is_active', true)->select('user_id'));

        return match ($this->audience) {
            BroadcastDispatch::AUDIENCE_SCHEDULE => $query
                ->whereIn('id', $this->travellerIdsForSchedules([$this->audienceId])),
            BroadcastDispatch::AUDIENCE_TRIP => $query
                ->whereIn('id', $this->travellerIdsForSchedules(
                    TripSchedule::where('trip_id', $this->audienceId)->pluck('id')->all()
                )),
            // Marketing blast to the whole base — honours the opt-out.
            default => $query->where('marketing_push_enabled', true),
        };
    }

    /**
     * Everyone holding a live booking on any of the given rounds.
     *
     * @param  array<int, int|null>  $scheduleIds
     * @return array<int, int>
     */
    private function travellerIdsForSchedules(array $scheduleIds): array
    {
        $scheduleIds = array_filter($scheduleIds);
        if (empty($scheduleIds)) {
            return [];
        }

        return Booking::whereIn('schedule_id', $scheduleIds)
            ->whereIn('status', ['pending', 'confirmed', 'completed'])
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->all();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendBroadcastNotificationJob failed', [
            'type' => $this->type,
            'error' => $exception->getMessage(),
        ]);
    }
}
