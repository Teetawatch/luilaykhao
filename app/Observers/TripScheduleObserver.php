<?php

namespace App\Observers;

use App\Models\TripSchedule;
use App\Services\BroadcastNotificationService;
use App\Services\TripAlertService;

class TripScheduleObserver
{
    public function __construct(
        private TripAlertService $tripAlertService,
        private BroadcastNotificationService $broadcast,
    ) {}

    public function created(TripSchedule $schedule): void
    {
        if ($schedule->status === 'open') {
            $this->notifyNewRound($schedule);
        }
    }

    public function updated(TripSchedule $schedule): void
    {
        // Fire only when a draft/closed schedule transitions into being bookable.
        if ($schedule->wasChanged('status') && $schedule->status === 'open') {
            $this->notifyNewRound($schedule);
        }
    }

    /**
     * Alert subscribers of this trip and broadcast the new round to the whole
     * customer base. Both senders dedupe internally, so re-entry is safe.
     */
    private function notifyNewRound(TripSchedule $schedule): void
    {
        $this->tripAlertService->notifyNewSchedule($schedule);
        $this->broadcast->broadcastNewSchedule($schedule);
    }
}
