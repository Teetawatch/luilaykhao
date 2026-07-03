<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Http\Resources\TripScheduleResource;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Services\WeatherService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TripController extends Controller
{
    use ApiResponse;

    public function __construct(private WeatherService $weatherService) {}

    public function index(Request $request): JsonResponse
    {
        $query = Trip::query()->where('status', 'active');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('location')) {
            $query->whereLike('location', "%{$request->location}%");
        }
        if ($request->filled('difficulty')) {
            $query->where('difficulty', $request->difficulty);
        }
        if ($request->filled('min_days')) {
            $query->where('duration_days', '>=', (int) $request->min_days);
        }
        if ($request->filled('max_days')) {
            $query->where('duration_days', '<=', (int) $request->max_days);
        }
        if ($request->filled('date')) {
            $query->whereHas('schedules', function ($q) use ($request) {
                $q->where('departure_date', $request->date)->where('status', 'open');
            });
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereLike('title', "%{$search}%")
                    ->orWhereLike('location', "%{$search}%")
                    ->orWhereLike('description', "%{$search}%");
            });
        }

        // เรียงเริ่มต้น "ทริปยอดนิยม" = จำนวนคนจอง (ผู้โดยสารในบุ๊กกิ้งที่ยืนยัน/เสร็จสิ้น) มากไปน้อย
        // ใช้ subquery เพื่อให้เรียงถูกต้องข้ามหน้า (pagination) ไม่ใช่แค่หน้าปัจจุบัน
        $bookedPassengersCount = BookingPassenger::query()
            ->selectRaw('count(*)')
            ->join('bookings', 'booking_passengers.booking_id', '=', 'bookings.id')
            ->join('trip_schedules', 'bookings.schedule_id', '=', 'trip_schedules.id')
            ->whereColumn('trip_schedules.trip_id', 'trips.id')
            ->whereIn('bookings.status', ['confirmed', 'completed']);

        $trips = $query->with(['schedules' => function ($q) {
            $q->where('departure_date', '>=', now()->startOfDay())->with('pickupPoints');
        }])->withCount(['schedules' => function ($q) {
            $q->where('status', 'open')->where('departure_date', '>=', now()->startOfDay());
        }])->orderByDesc($bookedPassengersCount)
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 12);
        $trips->getCollection()->each(fn ($trip) => $trip->schedules->each->syncBookedSeats());

        return $this->paginated($trips->through(fn ($trip) => new TripResource($trip)));
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

    /**
     * Trips with at least one OPEN upcoming round that is almost full
     * (1–5 seats left). Sorted most-urgent first. Powers the "ใกล้เต็มแล้ว" rail.
     */
    public function almostFull(): JsonResponse
    {
        $trips = Trip::where('status', 'active')
            ->with(['photos', 'schedules' => function ($q) {
                $q->where('status', 'open')
                    ->where('departure_date', '>=', now()->startOfDay())
                    ->with('pickupPoints');
            }])
            ->get();

        $trips->each(fn ($trip) => $trip->schedules->each->syncBookedSeats());

        $almost = $trips
            ->map(function ($trip) {
                $seats = $trip->schedules
                    ->filter(fn ($s) => $s->available_seats > 0)
                    ->min(fn ($s) => $s->available_seats);

                return ['trip' => $trip, 'seats' => $seats];
            })
            ->filter(fn ($row) => $row['seats'] !== null && $row['seats'] <= 5)
            ->sortBy('seats')
            ->take(10)
            ->map(fn ($row) => $row['trip'])
            ->values();

        return $this->success(TripResource::collection($almost));
    }

    /**
     * Trips with at least one round on a live flash sale, soonest-ending first.
     * Powers the "⚡ Flash Sale" home rail and the flash-sale push CTA.
     */
    public function flashSale(): JsonResponse
    {
        $trips = Trip::where('status', 'active')
            ->whereHas('schedules', fn ($q) => $q
                ->where('flash_sale_enabled', true)
                ->where('status', 'open')
                ->where('departure_date', '>=', now()->startOfDay()))
            ->with(['photos', 'schedules' => function ($q) {
                $q->where('departure_date', '>=', now()->startOfDay())->with('pickupPoints');
            }])
            ->get();

        $trips->each(fn ($trip) => $trip->schedules->each->syncBookedSeats());

        // whereHas prefilters cheaply; flashSaleActive() is the source of truth
        // (price/seats/end time), so re-check in PHP after syncing booked seats.
        $flash = $trips
            ->map(fn ($trip) => [
                'trip' => $trip,
                'ends' => $trip->schedules
                    ->filter(fn ($s) => $s->flashSaleActive())
                    ->min(fn ($s) => $s->flash_sale_ends_at?->timestamp ?? PHP_INT_MAX),
            ])
            ->filter(fn ($row) => $row['ends'] !== null)
            ->sortBy('ends')
            ->take(10)
            ->map(fn ($row) => $row['trip'])
            ->values();

        return $this->success(TripResource::collection($flash));
    }

    public function show(string $slug, Request $request): JsonResponse
    {
        $trip = Trip::where('slug', $slug)
            ->with(['photos', 'schedules' => function ($q) {
                $q->where('departure_date', '>=', now()->startOfDay())->with('pickupPoints');
            }])
            ->firstOrFail();

        $this->registerView($trip, $request);

        $trip->schedules->each->syncBookedSeats();
        $trip->schedules->each(fn ($s) => $this->weatherService->attach($s, $trip));

        return $this->success(new TripResource($trip->loadCount(['schedules' => function ($q) {
            $q->where('status', 'open')->where('departure_date', '>=', now()->startOfDay());
        }])));
    }

    /**
     * Count a unique-ish trip view. The same visitor (IP + user agent) only
     * bumps the counter once per 30 minutes so refreshes don't inflate it.
     */
    private function registerView(Trip $trip, Request $request): void
    {
        $key = 'trip_view:'.$trip->id.':'.sha1($request->ip().'|'.$request->userAgent());

        if (Cache::add($key, true, now()->addMinutes(30))) {
            $trip->increment('views_count');
        }
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
        $schedules->each(fn ($s) => $this->weatherService->attach($s, $trip));

        return $this->success(TripScheduleResource::collection($schedules));
    }
}
