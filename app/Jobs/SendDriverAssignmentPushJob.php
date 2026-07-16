<?php

namespace App\Jobs;

use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * แจ้งเตือนคนขับทันทีที่รถของเขาถูกมอบหมายเข้ารอบเดินทาง (in-app + FCM push).
 *
 * คนขับผูกกับรอบผ่านรถ (vehicle.driver_user_id หรือ vehicle.driver_phone)
 * — คนละทางกับสตาฟ ([[SendStaffAssignmentPushJob]]) จึงต้องแจ้งแยก.
 */
class SendDriverAssignmentPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 15;

    public function __construct(
        public readonly int $scheduleId,
        public readonly int $vehicleId,
    ) {}

    public function handle(): void
    {
        $schedule = TripSchedule::with('trip')->find($this->scheduleId);
        $vehicle = Vehicle::find($this->vehicleId);

        if (! $schedule || ! $vehicle) {
            return;
        }

        $driverUserId = $this->resolveDriverUserId($vehicle);
        if (! $driverUserId) {
            return;
        }

        $tripTitle = $schedule->trip?->title ?? 'ทริป';
        $body = "{$tripTitle} · {$schedule->departureLabelThai()}";
        if ($plate = $vehicle->license_plate) {
            $body .= " · {$plate}";
        }

        SmartNotification::send(
            $driverUserId,
            'driver_assignment',
            '🚐 คุณได้รับมอบหมายทริปใหม่',
            $body,
            [
                'type' => 'driver_assignment',
                'route' => 'driver_assignment',
                'schedule_id' => (string) $schedule->id,
                'trip_id' => (string) ($schedule->trip_id ?? ''),
                'trip_title' => $tripTitle,
                'departure_date' => $schedule->departure_date?->toDateString() ?? '',
            ],
        );
    }

    /**
     * Prefer the linked driver account; fall back to matching a user by the
     * vehicle's driver phone (digits only, so formatting differences match).
     */
    private function resolveDriverUserId(Vehicle $vehicle): ?int
    {
        if ($vehicle->driver_user_id) {
            return (int) $vehicle->driver_user_id;
        }

        $phone = preg_replace('/\D+/', '', (string) $vehicle->driver_phone);
        if ($phone === '') {
            return null;
        }

        return User::whereRaw("REPLACE(REPLACE(REPLACE(phone, '-', ''), ' ', ''), '+', '') = ?", [$phone])
            ->value('id');
    }
}
