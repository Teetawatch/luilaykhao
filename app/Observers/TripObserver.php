<?php

namespace App\Observers;

use App\Models\Trip;
use App\Services\BroadcastNotificationService;

class TripObserver
{
    public function __construct(
        private BroadcastNotificationService $broadcast,
    ) {}

    public function created(Trip $trip): void
    {
        // A trip created already-live → announce it.
        if ($trip->status === 'active') {
            $this->broadcast->broadcastNewTrip($trip);
        }
    }

    public function updated(Trip $trip): void
    {
        // Fire only when a draft/hidden trip is published. The dedupe ledger
        // makes sure toggling status back and forth never re-announces.
        if ($trip->wasChanged('status') && $trip->status === 'active') {
            $this->broadcast->broadcastNewTrip($trip);
        }
    }
}
