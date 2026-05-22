<?php

namespace App\Observers;

use App\Models\TripSchedule;
use App\Services\TripAlertService;

class TripScheduleObserver
{
    public function __construct(
        private TripAlertService $tripAlertService,
    ) {}

    public function created(TripSchedule $schedule): void
    {
        if ($schedule->status === 'open') {
            $this->tripAlertService->notifyNewSchedule($schedule);
        }
    }

    public function updated(TripSchedule $schedule): void
    {
        // Fire only when a draft/closed schedule transitions into being bookable.
        if ($schedule->wasChanged('status') && $schedule->status === 'open') {
            $this->tripAlertService->notifyNewSchedule($schedule);
        }
    }
}
