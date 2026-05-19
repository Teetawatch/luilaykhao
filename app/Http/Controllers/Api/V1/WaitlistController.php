<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TripSchedule;
use App\Models\WaitlistEntry;
use App\Services\WaitlistService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaitlistController extends Controller
{
    use ApiResponse;

    public function __construct(private WaitlistService $waitlistService) {}

    public function join(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'seat_count' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            $entry = $this->waitlistService->join(
                userId: $request->user()->id,
                scheduleId: $scheduleId,
                seatCount: $validated['seat_count'] ?? 1,
            );

            return $this->success(
                $this->formatEntry($entry),
                'เพิ่มเข้าคิวรอสำเร็จ',
                201
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function leave(Request $request, int $scheduleId): JsonResponse
    {
        $left = $this->waitlistService->leave($request->user()->id, $scheduleId);

        if (!$left) {
            return $this->error('ไม่พบรายการคิวรอของคุณ', 404);
        }

        return $this->success(null, 'ออกจากคิวรอสำเร็จ');
    }

    public function myEntries(Request $request): JsonResponse
    {
        $entries = $this->waitlistService->myEntries($request->user()->id);

        return $this->success(
            $entries->map(fn ($entry) => $this->formatEntry($entry))->values()
        );
    }

    public function scheduleStatus(Request $request, int $scheduleId): JsonResponse
    {
        $entry = $this->waitlistService->entryForSchedule($request->user()->id, $scheduleId);

        if (!$entry) {
            return $this->success(['in_waitlist' => false]);
        }

        $entry->load('schedule.trip');

        return $this->success([
            'in_waitlist' => true,
            ...$this->formatEntry($entry),
        ]);
    }

    public function adminScheduleWaitlist(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);

        $entries = WaitlistEntry::where('schedule_id', $scheduleId)
            ->with('user')
            ->orderBy('created_at')
            ->paginate($request->get('per_page', 50));

        return $this->paginated($entries->through(fn ($entry) => [
            'id' => $entry->id,
            'user' => [
                'id' => $entry->user?->id,
                'name' => $entry->user?->name,
                'email' => $entry->user?->email,
                'phone' => $entry->user?->phone,
            ],
            'seat_count' => $entry->seat_count,
            'status' => $entry->status,
            'position' => $entry->status === 'waiting'
                ? $this->waitlistService->positionInQueue($entry)
                : null,
            'offered_at' => $entry->offered_at?->toISOString(),
            'expires_at' => $entry->expires_at?->toISOString(),
            'created_at' => $entry->created_at?->toISOString(),
        ]));
    }

    private function formatEntry(WaitlistEntry $entry): array
    {
        $schedule = $entry->relationLoaded('schedule') ? $entry->schedule : null;
        $trip = $schedule?->relationLoaded('trip') ? $schedule->trip : null;

        return [
            'id' => $entry->id,
            'schedule_id' => $entry->schedule_id,
            'schedule' => $schedule ? [
                'id' => $schedule->id,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'return_date' => $schedule->return_date?->toDateString(),
                'available_seats' => $schedule->available_seats,
                'status' => $schedule->status,
                'price' => $schedule->effective_price,
                'trip' => $trip ? [
                    'id' => $trip->id,
                    'title' => $trip->title,
                    'slug' => $trip->slug,
                    'location' => $trip->location,
                    'thumbnail_image' => $trip->thumbnail_image,
                    'cover_image' => $trip->cover_image,
                ] : null,
            ] : null,
            'seat_count' => $entry->seat_count,
            'status' => $entry->status,
            'position' => $entry->status === 'waiting'
                ? $this->waitlistService->positionInQueue($entry)
                : null,
            'offered_at' => $entry->offered_at?->toISOString(),
            'expires_at' => $entry->expires_at?->toISOString(),
            'expires_in_seconds' => $entry->status === 'offered' && $entry->expires_at?->isFuture()
                ? (int) now()->diffInSeconds($entry->expires_at)
                : null,
            'created_at' => $entry->created_at?->toISOString(),
        ];
    }
}
