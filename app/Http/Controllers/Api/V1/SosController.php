<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\BroadcastSosAlert;
use App\Models\Booking;
use App\Models\SosAlert;
use App\Models\TripSchedule;
use App\Support\MediaDisk;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SosController extends Controller
{
    use ApiResponse;

    public function trigger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_id' => ['required', 'integer'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'message' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        $user = $request->user();

        $booking = Booking::where('user_id', $user->id)
            ->where('schedule_id', $validated['schedule_id'])
            ->where('status', 'confirmed')
            ->first();

        if (! $booking) {
            return $this->error('ไม่พบการจองที่ยืนยันแล้วในทริปนี้', 404);
        }

        $schedule = TripSchedule::with('trip')->find($validated['schedule_id']);

        if (! $schedule || ! $this->isWithinTripWindow($schedule)) {
            return $this->error('ใช้ SOS ได้เฉพาะช่วงเวลาทริปเท่านั้น', 422);
        }

        // Treat a repeated trigger within a short window as the same alert so a
        // client retrying over a flaky connection doesn't create duplicates.
        $recentAlert = SosAlert::where('user_id', $user->id)
            ->where('schedule_id', $schedule->id)
            ->where('status', 'active')
            ->where('created_at', '>=', now()->subMinutes(2))
            ->latest()
            ->first();

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('sos/'.date('Y/m'), MediaDisk::name());
        }

        if ($recentAlert) {
            // A retry may carry the photo that an earlier attempt failed to upload —
            // attach it to the existing alert rather than creating a duplicate.
            if ($photoPath && ! $recentAlert->photo_path) {
                $recentAlert->update(['photo_path' => $photoPath]);
                BroadcastSosAlert::dispatchAfterResponse($recentAlert->id);
            }

            return $this->success($this->presentAlert($recentAlert->fresh('user')), 'ส่งสัญญาณ SOS แล้ว');
        }

        $alert = SosAlert::create([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'message' => $validated['message'] ?? null,
            'photo_path' => $photoPath,
            'contact_phone' => $user->phone,
            'status' => 'active',
        ]);

        // Notify recipients after the response is sent so a slow FCM round-trip
        // never holds up the sender — critical on weak (3G) connections.
        BroadcastSosAlert::dispatchAfterResponse($alert->id);

        return $this->success($this->presentAlert($alert->fresh('user')), 'ส่งสัญญาณ SOS แล้ว');
    }

    public function active(Request $request): JsonResponse
    {
        $user = $request->user();

        $scheduleIds = Booking::where('user_id', $user->id)
            ->where('status', 'confirmed')
            ->pluck('schedule_id')
            ->unique()
            ->values();

        $alerts = SosAlert::with('user')
            ->whereIn('schedule_id', $scheduleIds)
            ->where('status', 'active')
            ->where('user_id', '!=', $user->id)
            ->orderByDesc('created_at')
            ->get();

        return $this->success($alerts->map(fn ($a) => $this->presentAlert($a))->values());
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $alert = SosAlert::findOrFail($id);

        $onSameSchedule = Booking::where('user_id', $user->id)
            ->where('schedule_id', $alert->schedule_id)
            ->where('status', 'confirmed')
            ->exists()
            || $alert->schedule->staff()->where('users.id', $user->id)->exists();

        if (! $onSameSchedule) {
            return $this->error('คุณไม่มีสิทธิ์ปิดเคสนี้', 403);
        }

        if ($alert->status === 'active') {
            $alert->update([
                'status' => 'resolved',
                'resolved_by' => $user->id,
                'resolved_at' => now(),
            ]);
        }

        return $this->success($this->presentAlert($alert->fresh('user')), 'ปิดเคส SOS แล้ว');
    }

    private function isWithinTripWindow(TripSchedule $schedule): bool
    {
        $today = now(TripSchedule::REVIEW_AVAILABLE_TIMEZONE)->toDateString();
        // เปิด SOS ตั้งแต่ 1 วันก่อนเดินทาง (ตรงกับฝั่งแอป) จนถึงวันเดินทางกลับ
        $start = $schedule->departure_date?->copy()->subDay()->toDateString();
        $end = ($schedule->return_date ?? $schedule->departure_date)?->toDateString();

        if (! $start) {
            return false;
        }

        return $today >= $start && $today <= $end;
    }

    private function presentAlert(SosAlert $alert): array
    {
        return [
            'id' => $alert->id,
            'schedule_id' => $alert->schedule_id,
            'user_name' => $alert->user?->name,
            'message' => $alert->message,
            'photo_url' => MediaDisk::url($alert->photo_path),
            'contact_phone' => $alert->contact_phone,
            'latitude' => $alert->latitude,
            'longitude' => $alert->longitude,
            'status' => $alert->status,
            'created_at' => $alert->created_at?->toISOString(),
            'resolved_at' => $alert->resolved_at?->toISOString(),
        ];
    }
}
