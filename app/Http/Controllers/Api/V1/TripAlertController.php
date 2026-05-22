<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\TripAlert;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripAlertController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $alerts = TripAlert::with('trip')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TripAlert $alert) => $this->format($alert));

        return $this->success($alerts);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $trip = Trip::where('slug', $slug)->firstOrFail();

        $validated = $request->validate([
            'alert_price_drop' => ['sometimes', 'boolean'],
            'alert_new_schedule' => ['sometimes', 'boolean'],
            'alert_low_seats' => ['sometimes', 'boolean'],
            'low_seat_threshold' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $alert = TripAlert::updateOrCreate(
            ['user_id' => $request->user()->id, 'trip_id' => $trip->id],
            array_merge([
                'alert_price_drop' => true,
                'alert_new_schedule' => true,
                'alert_low_seats' => true,
            ], $validated),
        );

        return $this->success($this->format($alert->load('trip')), 'เปิดการแจ้งเตือนทริปนี้แล้ว', 201);
    }

    public function destroy(Request $request, string $slug): JsonResponse
    {
        $trip = Trip::where('slug', $slug)->firstOrFail();

        TripAlert::where('user_id', $request->user()->id)
            ->where('trip_id', $trip->id)
            ->delete();

        return $this->success(null, 'ปิดการแจ้งเตือนทริปนี้แล้ว');
    }

    private function format(TripAlert $alert): array
    {
        return [
            'id' => $alert->id,
            'trip_id' => $alert->trip_id,
            'trip_slug' => $alert->trip?->slug,
            'trip_title' => $alert->trip?->title,
            'alert_price_drop' => $alert->alert_price_drop,
            'alert_new_schedule' => $alert->alert_new_schedule,
            'alert_low_seats' => $alert->alert_low_seats,
            'low_seat_threshold' => $alert->low_seat_threshold,
            'created_at' => $alert->created_at?->toISOString(),
        ];
    }
}
