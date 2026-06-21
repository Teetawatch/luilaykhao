<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\BroadcastIncidentReport;
use App\Models\Booking;
use App\Models\Incident;
use App\Models\TripSchedule;
use App\Support\MediaDisk;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IncidentController extends Controller
{
    use ApiResponse;

    // Staff reports an on-trip incident (accident, injury, etc.).
    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with(['trip', 'staff', 'vehicle'])->find($scheduleId);

        if (! $schedule) {
            return $this->error('ไม่พบรอบเดินทางนี้', 404);
        }

        if (! $this->canAccessSchedule($request, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์แจ้งเหตุในรอบเดินทางนี้', 403);
        }

        $validated = $request->validate([
            'booking_id' => ['nullable', 'integer'],
            'passenger_name' => ['nullable', 'string', 'max:255'],
            'severity' => ['required', Rule::in(Incident::SEVERITIES)],
            'description' => ['required', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        // A booking_id, if given, must belong to this schedule.
        if (! empty($validated['booking_id'])) {
            $belongs = Booking::where('id', $validated['booking_id'])
                ->where('schedule_id', $schedule->id)
                ->exists();
            if (! $belongs) {
                return $this->error('การจองที่อ้างถึงไม่อยู่ในรอบเดินทางนี้', 422);
            }
        }

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('incidents/'.date('Y/m'), MediaDisk::name());
        }

        $incident = Incident::create([
            'schedule_id' => $schedule->id,
            'reported_by' => $request->user()->id,
            'booking_id' => $validated['booking_id'] ?? null,
            'passenger_name' => $validated['passenger_name'] ?? null,
            'severity' => $validated['severity'],
            'description' => $validated['description'],
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'photo_path' => $photoPath,
            'status' => 'open',
        ]);

        // Notify ops/admin/staff after the response is sent so a slow FCM
        // round-trip never holds up the reporter on a weak connection.
        BroadcastIncidentReport::dispatchAfterResponse($incident->id);

        return $this->success($this->present($incident->fresh(['reporter', 'resolver'])), 'แจ้งเหตุเรียบร้อยแล้ว');
    }

    // Incidents logged for a schedule (most recent first).
    public function index(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with(['staff', 'vehicle'])->find($scheduleId);

        if (! $schedule) {
            return $this->error('ไม่พบรอบเดินทางนี้', 404);
        }

        if (! $this->canAccessSchedule($request, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์ดูรอบเดินทางนี้', 403);
        }

        $incidents = Incident::with(['reporter', 'resolver'])
            ->where('schedule_id', $schedule->id)
            ->orderByDesc('created_at')
            ->get();

        return $this->success($incidents->map(fn (Incident $i) => $this->present($i))->values());
    }

    // Admin/operator list across all schedules, optionally filtered by status.
    public function adminIndex(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $query = Incident::with(['schedule.trip', 'reporter', 'resolver'])
            ->orderByDesc('created_at');

        if (in_array($status, ['open', 'resolved'], true)) {
            $query->where('status', $status);
        }

        $incidents = $query->limit(300)->get();

        return $this->success($incidents->map(fn (Incident $i) => $this->present($i))->values());
    }

    // Mark an incident resolved.
    public function resolve(Request $request, int $id): JsonResponse
    {
        $incident = Incident::with(['schedule.staff', 'schedule.vehicle'])->find($id);

        if (! $incident) {
            return $this->error('ไม่พบรายการแจ้งเหตุนี้', 404);
        }

        if (! $this->canAccessSchedule($request, $incident->schedule)) {
            return $this->error('คุณไม่มีสิทธิ์ปิดเคสนี้', 403);
        }

        if ($incident->status !== 'resolved') {
            $incident->update([
                'status' => 'resolved',
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
            ]);
        }

        return $this->success($this->present($incident->fresh(['reporter', 'resolver'])), 'ปิดเคสแล้ว');
    }

    private function canAccessSchedule(Request $request, ?TripSchedule $schedule): bool
    {
        if (! $schedule) {
            return false;
        }

        $user = $request->user();

        if ($user->hasAnyRole(['admin', 'operator'])) {
            return true;
        }

        if ($schedule->staff?->contains(fn ($staff) => (int) $staff->id === (int) $user->id)) {
            return true;
        }

        return $schedule->vehicle && (int) $schedule->vehicle->driver_user_id === (int) $user->id;
    }

    private function present(Incident $incident): array
    {
        return [
            'id' => $incident->id,
            'schedule_id' => $incident->schedule_id,
            'booking_id' => $incident->booking_id,
            'passenger_name' => $incident->passenger_name,
            'severity' => $incident->severity,
            'severity_label' => BroadcastIncidentReport::severityLabel($incident->severity),
            'description' => $incident->description,
            'latitude' => $incident->latitude,
            'longitude' => $incident->longitude,
            'photo_url' => MediaDisk::url($incident->photo_path),
            'status' => $incident->status,
            'reported_by_name' => $incident->reporter?->name,
            'resolved_by_name' => $incident->resolver?->name,
            'resolved_at' => $incident->resolved_at?->toISOString(),
            'created_at' => $incident->created_at?->toISOString(),
            // Trip context — only populated when the schedule/trip is eager
            // loaded (admin list); null elsewhere.
            'trip_title' => $incident->schedule?->trip?->title,
            'departure_date' => $incident->schedule?->departure_date?->toDateString(),
        ];
    }
}
