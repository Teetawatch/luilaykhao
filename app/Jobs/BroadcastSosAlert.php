<?php

namespace App\Jobs;

use App\Events\SosTriggered;
use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\SosAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BroadcastSosAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(private int $sosAlertId) {}

    public function handle(): void
    {
        $alert = SosAlert::with(['user', 'schedule.trip'])->find($this->sosAlertId);

        if (! $alert || ! $alert->schedule || ! $alert->user) {
            return;
        }

        $schedule = $alert->schedule;
        $sender = $alert->user;

        $staffIds = $schedule->staff()->pluck('users.id');
        $travelerIds = Booking::where('schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->pluck('user_id');

        $recipientIds = $staffIds->merge($travelerIds)
            ->unique()
            ->reject(fn ($id) => (int) $id === (int) $sender->id)
            ->values();

        $photoUrl = $alert->photo_path ? Storage::disk('public')->url($alert->photo_path) : null;

        $tripTitle = $schedule->trip?->title ?? 'ทริป';
        $title = '🆘 ขอความช่วยเหลือ SOS';
        $body = $sender->name.' ขอความช่วยเหลือในทริป '.$tripTitle;
        if ($alert->message) {
            $body .= ' — '.$alert->message;
        }

        $data = [
            'sos_id' => (string) $alert->id,
            'schedule_id' => (string) $schedule->id,
            'sos_user_name' => (string) $sender->name,
            'contact_phone' => (string) ($alert->contact_phone ?? ''),
            'latitude' => $alert->latitude !== null ? (string) $alert->latitude : '',
            'longitude' => $alert->longitude !== null ? (string) $alert->longitude : '',
            'sos_message' => (string) ($alert->message ?? ''),
            'photo_url' => (string) ($photoUrl ?? ''),
        ];

        foreach ($recipientIds as $recipientId) {
            SmartNotification::send((int) $recipientId, 'sos_alert', $title, $body, $data);
            broadcast(new SosTriggered(
                recipientUserId: (int) $recipientId,
                sosId: $alert->id,
                scheduleId: $schedule->id,
                userName: $sender->name,
                message: $alert->message,
                contactPhone: $alert->contact_phone,
                latitude: $alert->latitude,
                longitude: $alert->longitude,
                photoUrl: $photoUrl,
            ));
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('BroadcastSosAlert failed permanently', [
            'sos_alert_id' => $this->sosAlertId,
            'error' => $exception->getMessage(),
        ]);
    }
}
