<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Models\SmartNotification;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Notifies the people who handle operations — admins, operators and the staff
// assigned to this schedule — when a staff member reports an on-trip incident.
// Customers are intentionally not notified.
class BroadcastIncidentReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(private int $incidentId) {}

    public function handle(): void
    {
        $incident = Incident::with(['schedule.trip', 'reporter'])->find($this->incidentId);

        if (! $incident || ! $incident->schedule) {
            return;
        }

        $schedule = $incident->schedule;
        $reporter = $incident->reporter;

        // whereHas (not the role() scope) so a missing role name never throws.
        $opsIds = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'operator']))
            ->pluck('id');
        $staffIds = $schedule->staff()->pluck('users.id');

        $recipientIds = $opsIds->merge($staffIds)
            ->unique()
            ->reject(fn ($id) => (int) $id === (int) ($reporter?->id))
            ->values();

        $tripTitle = $schedule->trip?->title ?? 'ทริป';
        $who = $incident->passenger_name ?: 'ผู้โดยสาร';
        $severityLabel = self::severityLabel($incident->severity);

        $title = '⚠️ แจ้งเหตุในทริป ('.$severityLabel.')';
        $body = ($reporter?->name ?? 'สตาฟ').' รายงานเหตุของ '.$who.' ในทริป '.$tripTitle
            .' — '.Str::limit($incident->description, 120);

        $data = [
            'incident_id' => (string) $incident->id,
            'schedule_id' => (string) $schedule->id,
            'severity' => (string) $incident->severity,
            'passenger_name' => (string) ($incident->passenger_name ?? ''),
            'reported_by' => (string) ($reporter?->name ?? ''),
            'latitude' => $incident->latitude !== null ? (string) $incident->latitude : '',
            'longitude' => $incident->longitude !== null ? (string) $incident->longitude : '',
            'photo_url' => (string) (MediaDisk::url($incident->photo_path) ?? ''),
        ];

        foreach ($recipientIds as $recipientId) {
            SmartNotification::send((int) $recipientId, 'incident_report', $title, $body, $data);
        }
    }

    public static function severityLabel(string $severity): string
    {
        return match ($severity) {
            'minor' => 'เล็กน้อย',
            'severe' => 'รุนแรง',
            'critical' => 'วิกฤต',
            default => 'ปานกลาง',
        };
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('BroadcastIncidentReport failed permanently', [
            'incident_id' => $this->incidentId,
            'error' => $exception->getMessage(),
        ]);
    }
}
