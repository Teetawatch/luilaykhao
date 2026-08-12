<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Http\Resources\TripScheduleResource;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Services\WeatherService;
use App\Support\MediaDisk;
use App\Support\ThaiDate;
use App\Support\UrgentPopupSettings;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        // ในประเทศ / ต่างประเทศ — แท็บหลักของหน้ารวมทริป
        if (in_array($request->input('destination'), ['domestic', 'international'], true)) {
            $query->where('destination_type', $request->input('destination'));
        }
        if ($request->filled('country')) {
            $query->where('country_code', strtoupper($request->country));
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

        $query->with(['schedules' => function ($q) {
            $q->where('departure_date', '>=', now()->startOfDay())->with('pickupPoints');
        }])->withCount(['schedules' => function ($q) {
            $q->where('status', 'open')->where('departure_date', '>=', now()->startOfDay());
        }]);

        // การเรียงต้องทำที่นี่ ไม่ใช่ฝั่งหน้าเว็บ ไม่งั้น "ราคาน้อยไปมาก" จะเรียงแค่ทริปในหน้าปัจจุบัน
        match ($request->input('sort')) {
            'price_asc' => $query->orderBy('price_per_person'),
            'price_desc' => $query->orderByDesc('price_per_person'),
            default => $query->orderByDesc($bookedPassengersCount),
        };

        $trips = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 12);
        $trips->getCollection()->each(fn ($trip) => $trip->schedules->each->syncBookedSeats());

        return $this->paginated($trips->through(fn ($trip) => new TripResource($trip)));
    }

    /**
     * ทริปทั้งหมดที่ปักหมุดบนแผนที่ได้ — payload บางกว่า index() มาก เพราะหน้าแผนที่
     * ต้องโหลดทุกหมุดพร้อมกันในครั้งเดียว ไม่ได้แบ่งหน้า จึงคืนเฉพาะสิ่งที่ป้ายหมุด
     * และการ์ดสรุปต้องใช้จริง ๆ. ทริปที่ยังไม่มีพิกัดถูกตัดออก เพราะปักไม่ได้อยู่ดี.
     */
    public function map(): JsonResponse
    {
        $trips = Cache::remember('trips-map', now()->addMinutes(10), function () {
            return Trip::where('status', 'active')
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->with(['schedules' => function ($q) {
                    $q->where('status', 'open')
                        ->where('departure_date', '>=', now('Asia/Bangkok')->startOfDay())
                        ->orderBy('departure_date');
                }])
                ->get()
                ->map(function (Trip $trip) {
                    $upcoming = $trip->schedules;
                    $next = $upcoming->first();

                    return [
                        'id' => $trip->id,
                        'title' => $trip->title,
                        'slug' => $trip->slug,
                        'location' => $trip->location,
                        'region' => $trip->region,
                        'destination_type' => $trip->destination_type,
                        'country_code' => $trip->country_code,
                        'country_label' => $trip->countryLabel(),
                        'type' => $trip->type,
                        'difficulty' => $trip->difficulty,
                        'duration_days' => $trip->duration_days,
                        'cover_image' => MediaDisk::url($trip->thumbnail_image ?: $trip->cover_image),
                        'latitude' => (float) $trip->latitude,
                        'longitude' => (float) $trip->longitude,
                        // ราคาที่โชว์บนหมุดคือราคาถูกที่สุดที่จองได้จริงในรอบข้างหน้า
                        'price_from' => (float) ($upcoming
                            ->map(fn ($schedule) => $schedule->effective_price)
                            ->min() ?? $trip->price_per_person),
                        'upcoming_count' => $upcoming->count(),
                        'next_departure' => $next?->departure_date?->toDateString(),
                        'next_departure_label' => $next?->departure_date
                            ? ThaiDate::short($next->departure_date)
                            : null,
                        // เดือนที่มีรอบเปิด (1–12) ใช้กรอง "ไปเดือนไหนดี" ฝั่งหน้าเว็บ
                        'months' => $upcoming
                            ->map(fn ($schedule) => (int) $schedule->departure_date->format('n'))
                            ->unique()
                            ->values()
                            ->all(),
                    ];
                })
                ->values();
        });

        return $this->success($trips);
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
        return $this->success(TripResource::collection($this->almostFullTrips()));
    }

    /**
     * Trips with at least one round on a live flash sale, soonest-ending first.
     * Powers the "⚡ Flash Sale" home rail and the flash-sale push CTA.
     */
    public function flashSale(): JsonResponse
    {
        return $this->success(TripResource::collection($this->flashSaleTrips()));
    }

    /**
     * One-shot payload for the website's entry popup: flash-sale trips
     * (soonest-ending first) + almost-full trips, gated by the admin-editable
     * urgent-popup settings. A trip on flash sale is not repeated in the
     * almost-full list.
     */
    public function urgentPopup(): JsonResponse
    {
        $settings = UrgentPopupSettings::get();

        if (! $settings['enabled']) {
            return $this->success([
                'enabled' => false,
                'title' => $settings['title'],
                'flash_sale' => [],
                'almost_full' => [],
            ]);
        }

        $flash = $settings['show_flash_sale'] ? $this->flashSaleTrips(4) : collect();
        $almost = $settings['show_almost_full']
            ? $this->almostFullTrips((int) $settings['seat_threshold'], 4)
                ->reject(fn ($trip) => $flash->contains('id', $trip->id))
                ->values()
            : collect();

        return $this->success([
            'enabled' => true,
            'title' => $settings['title'],
            'flash_sale' => TripResource::collection($flash),
            'almost_full' => TripResource::collection($almost),
        ]);
    }

    /**
     * Active trips whose lowest-seat OPEN upcoming round has 1–$maxSeats seats
     * left, most-urgent first.
     */
    private function almostFullTrips(int $maxSeats = 5, int $limit = 10): Collection
    {
        $trips = Trip::where('status', 'active')
            ->with(['photos', 'schedules' => function ($q) {
                $q->where('status', 'open')
                    ->where('departure_date', '>=', now()->startOfDay())
                    ->with('pickupPoints');
            }])
            ->get();

        $trips->each(fn ($trip) => $trip->schedules->each->syncBookedSeats());

        return $trips
            ->map(function ($trip) {
                $seats = $trip->schedules
                    ->filter(fn ($s) => $s->available_seats > 0)
                    ->min(fn ($s) => $s->available_seats);

                return ['trip' => $trip, 'seats' => $seats];
            })
            ->filter(fn ($row) => $row['seats'] !== null && $row['seats'] <= $maxSeats)
            ->sortBy('seats')
            ->take($limit)
            ->map(fn ($row) => $row['trip'])
            ->values();
    }

    /** Active trips with a live flash-sale round, soonest-ending first. */
    private function flashSaleTrips(int $limit = 10): Collection
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
        return $trips
            ->map(fn ($trip) => [
                'trip' => $trip,
                'ends' => $trip->schedules
                    ->filter(fn ($s) => $s->flashSaleActive())
                    ->min(fn ($s) => $s->flash_sale_ends_at?->timestamp ?? PHP_INT_MAX),
            ])
            ->filter(fn ($row) => $row['ends'] !== null)
            ->sortBy('ends')
            ->take($limit)
            ->map(fn ($row) => $row['trip'])
            ->values();
    }

    public function show(string $slug, Request $request): JsonResponse
    {
        $trip = Trip::where('slug', $slug)
            ->with(['photos', 'schedules' => function ($q) {
                $q->where('status', 'open')
                    ->where('departure_date', '>=', now()->startOfDay())
                    ->with('pickupPoints')
                    // ที่นั่งที่กันไว้ให้คิวรอต้องไม่ถูกนับเป็นของว่างบนหน้าจอง
                    ->withHeldSeats()
                    ->orderBy('departure_date');
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
     * "You may also like" — other active trips ranked by relatedness to the
     * given one (same type and/or region weighted highest, featured as a
     * tiebreaker, trips with an upcoming open round preferred). Returns up to 6.
     */
    public function related(string $slug): JsonResponse
    {
        $trip = Trip::where('slug', $slug)->firstOrFail();

        $candidates = Trip::where('status', 'active')
            ->where('id', '!=', $trip->id)
            ->with(['photos', 'schedules' => function ($q) {
                $q->where('departure_date', '>=', now()->startOfDay())->with('pickupPoints');
            }])
            ->get();

        $today = now()->startOfDay();

        $ranked = $candidates
            ->map(function ($candidate) use ($trip, $today) {
                $hasUpcoming = $candidate->schedules->contains(
                    fn ($s) => $s->status === 'open'
                        && $s->departure_date
                        && $s->departure_date->gte($today)
                );

                $score = 0;
                $score += $candidate->type === $trip->type ? 3 : 0;
                $score += $candidate->region === $trip->region ? 2 : 0;
                $score += $hasUpcoming ? 2 : 0;
                $score += $candidate->is_featured ? 1 : 0;

                return ['trip' => $candidate, 'score' => $score];
            })
            ->sortByDesc('score')
            ->take(6)
            ->pluck('trip')
            ->values();

        $ranked->each(fn ($t) => $t->schedules->each->syncBookedSeats());

        return $this->success(TripResource::collection($ranked));
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
