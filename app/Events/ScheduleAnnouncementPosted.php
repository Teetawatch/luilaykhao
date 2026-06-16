<?php

namespace App\Events;

use App\Models\ScheduleAnnouncement;
use App\Services\AnnouncementService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScheduleAnnouncementPosted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ScheduleAnnouncement $announcement,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("announcements.schedule.{$this->announcement->schedule_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'announcement.posted';
    }

    public function broadcastWith(): array
    {
        $this->announcement->loadMissing('author');

        return app(AnnouncementService::class)->present($this->announcement);
    }
}
