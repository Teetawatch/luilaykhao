<?php

namespace App\Jobs;

use App\Models\ScheduleAnnouncement;
use App\Models\SmartNotification;
use App\Services\ChatService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Fan an operator announcement out to every member of the schedule (booked
 * customers + assigned staff), as a SmartNotification — i.e. an entry in the
 * in-app notification center *and* an FCM push. The author is excluded.
 */
class SendAnnouncementPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 15;

    public function __construct(
        public readonly int $announcementId,
        public readonly ?int $authorUserId = null,
    ) {}

    public function handle(ChatService $chatService): void
    {
        $announcement = ScheduleAnnouncement::with('schedule.trip')->find($this->announcementId);
        if (! $announcement || ! $announcement->schedule) {
            return;
        }

        $tripTitle = $announcement->schedule->trip?->title ?? 'ทริปของคุณ';
        $preview = Str::limit(trim($announcement->body), 120);

        $recipientIds = $chatService->pushRecipientIds($announcement->schedule)
            ->reject(fn ($id) => $this->authorUserId !== null && (int) $id === $this->authorUserId)
            ->unique();

        foreach ($recipientIds as $userId) {
            SmartNotification::send(
                (int) $userId,
                'schedule_announcement',
                "📢 $tripTitle",
                $announcement->title !== '' ? "{$announcement->title} — {$preview}" : $preview,
                [
                    'route' => 'announcement',
                    'schedule_id' => (string) $announcement->schedule_id,
                    'announcement_id' => (string) $announcement->id,
                    'category' => $announcement->category,
                ],
            );
        }
    }
}
