<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Http\Resources\TripScheduleResource;
use App\Models\Trip;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = Trip::query()->where('status', 'active');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('location')) {
            $query->where('location', 'like', "%{$request->location}%");
        }
        if ($request->filled('difficulty')) {
            $query->where('difficulty', $request->difficulty);
        }
        if ($request->filled('date')) {
            $query->whereHas('schedules', function ($q) use ($request) {
                $q->where('departure_date', $request->date)->where('status', 'open');
            });
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $trips = $query->with(['schedules' => function ($q) {
            $q->where('departure_date', '>=', now()->startOfDay())->with('pickupPoints');
        }])->withCount(['schedules' => function ($q) {
            $q->where('status', 'open')->where('departure_date', '>=', now()->startOfDay());
        }])->orderBy('created_at', 'desc')->paginate($request->per_page ?? 12);
        $trips->getCollection()->each(fn ($trip) => $trip->schedules->each->syncBookedSeats());

        return $this->paginated($trips->through(fn($trip) => new TripResource($trip)));
    }

    public function featured(): JsonResponse
    {
        $trips = Trip::where('status', 'active')
            ->where('is_featured', true)
            ->with(['photos', 'schedules' => function ($q) {
                $q->where('departure_date', '>=', now()->startOfDay())->with('pickupPoints');
            }])
            ->orderByDesc('created_at')
            ->get();
        $trips->each(fn ($trip) => $trip->schedules->each->syncBookedSeats());

        return $this->success(TripResource::collection($trips));
    }

    public function show(string $slug): JsonResponse
    {
        $trip = Trip::where('slug', $slug)
            ->with(['photos', 'schedules' => function ($q) {
                $q->where('departure_date', '>=', now()->startOfDay())->with('pickupPoints');
            }])
            ->firstOrFail();
        $trip->schedules->each->syncBookedSeats();

        return $this->success(new TripResource($trip->loadCount(['schedules' => function ($q) {
            $q->where('status', 'open')->where('departure_date', '>=', now()->startOfDay());
        }])));
    }

    public function schedules(string $slug, Request $request): JsonResponse
    {
        $trip = Trip::where('slug', $slug)->firstOrFail();

        $schedules = $trip->schedules()
            ->where('status', 'open')
            ->where('departure_date', '>=', now()->startOfDay())
            ->with(['vehicle', 'pickupPoints'])
            ->orderBy('departure_date')
            ->get();
        $schedules->each->syncBookedSeats();

        return $this->success(TripScheduleResource::collection($schedules));
    }
}
