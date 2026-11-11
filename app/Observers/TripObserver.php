<?php

namespace App\Observers;

use App\Jobs\GenerateTripCardImageJob;
use App\Models\Trip;
use App\Services\BroadcastNotificationService;

class TripObserver
{
    public function __construct(
        private BroadcastNotificationService $broadcast,
    ) {}

    public function created(Trip $trip): void
    {
        $this->queueCardImage($trip);

        // A trip created already-live → announce it.
        if ($trip->status === 'active') {
            $this->broadcast->broadcastNewTrip($trip);
        }
    }

    public function updated(Trip $trip): void
    {
        if ($trip->wasChanged(['thumbnail_image', 'cover_image'])) {
            $this->queueCardImage($trip);
        }

        // Fire only when a draft/hidden trip is published. The dedupe ledger
        // makes sure toggling status back and forth never re-announces.
        if ($trip->wasChanged('status') && $trip->status === 'active') {
            $this->broadcast->broadcastNewTrip($trip);
        }
    }

    /** ย่อรูปการ์ดใหม่เมื่อรูปเปลี่ยน — หลัง commit เพื่อให้ job เห็นแถวที่บันทึกแล้ว */
    private function queueCardImage(Trip $trip): void
    {
        if ($trip->cardImageSource() && $trip->cardImageUrl() === null) {
            GenerateTripCardImageJob::dispatch($trip->id)->afterCommit();
        }
    }
}
