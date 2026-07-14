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

        if ($schedule->flash_sale_enabled) {
            $this->broadcast->broadcastFlashSale($schedule);
        }
    }

    public function updated(TripSchedule $schedule): void
    {
        // Fire only when a draft/closed schedule transitions into being bookable.
        if ($schedule->wasChanged('status') && $schedule->status === 'open') {
            $this->notifyNewRound($schedule);
        }

        // Announce when a flash sale is switched on, or its terms change while on.
        // broadcastFlashSale self-guards on flashSaleActive(), so a sale scheduled
        // to start later no-ops here and is announced by StartScheduledFlashSalesJob
        // once its start passes. The dedupe ledger keeps this to one push per sale.
        $flashTermsChanged = $schedule->wasChanged([
            'flash_sale_enabled', 'flash_sale_price', 'flash_sale_starts_at', 'flash_sale_ends_at',
        ]);
        if ($schedule->flash_sale_enabled && $flashTermsChanged) {
            $this->broadcast->broadcastFlashSale($schedule);
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
