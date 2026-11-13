<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Http\Resources\TripScheduleResource;
use App\Models\BookingPassenger;
use App\Models\Place;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Services\WeatherService;
use App\Support\Countries;
use App\Support\MediaDisk;
use App\Support\ThaiDate;
use App\Support\TripListCache;
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
        return TripListCache::respond('index', $request, fn () => $this->buildIndex($request));
    }

    private function buildIndex(Request $request): JsonResponse
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
        // ภาคของไทย — คู่ขนานกับ country สำหรับทริปในประเทศ
        if ($request->filled('region')) {
            $query->where('region', $request->region);
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

        // เพดาน 100 — หน้าแรกของเว็บดึงทริปทั้งหมดในครั้งเดียว (per_page=100)
        // แต่ endpoint สาธารณะต้องไม่ยอมให้ใครขอทีละหลายพันแถว
        $trips = $query->orderBy('created_at', 'desc')->paginate(min(max((int) ($request->per_page ?: 12), 1), 100));
        TripSchedule::syncBookedSeatsFor($trips->getCollection()->flatMap->schedules);
        Trip::loadListStats($trips->getCollection());

        return $this->paginated($trips->through(fn ($trip) => new TripResource($trip)));
    }

    /**
     * ตัวเลือกปลายทางพร้อมจำนวนทริปจริงในแต่ละอัน — ใช้เติมแถบเลือกปลายทาง
     * บนหน้ารวมทริป: ในประเทศแยกตามภาค ต่างประเทศแยกตามประเทศ
     *
     * นับจากทริปที่เปิดขายเท่านั้น และคืนเฉพาะภาค/ประเทศที่มีทริปอยู่จริง
     * เพื่อไม่ให้ผู้ใช้กดปุ่มที่พาไปหน้าว่าง
     *
     * สิ่งที่เก็บลงแคชต้องเป็น array ล้วน — Collection ที่ถูก serialize ลง
     * cache driver แล้วอ่านกลับมาจะกลายเป็นออบเจ็กต์ ไม่ใช่ลิสต์ใน JSON
     */
    public function destinations(): JsonResponse
    {
        $facets = Cache::remember('trips-destinations', now()->addMinutes(10), function () {
            $counts = Trip::query()
                ->where('status', 'active')
                ->selectRaw('destination_type, region, country_code, count(*) as total')
                ->groupBy('destination_type', 'region', 'country_code')
                ->get();

            $domestic = $counts->where('destination_type', '!=', 'international');
            $international = $counts->where('destination_type', 'international');

            $regions = collect(Place::REGIONS)
                ->map(fn ($label, $key) => [
                    'key' => $key,
                    'label' => $label,
                    'count' => (int) $domestic->where('region', $key)->sum('total'),
                ])
                ->filter(fn ($region) => $region['count'] > 0)
                ->values()
                ->all();

            $countries = $international
                ->groupBy('country_code')
                ->map(fn ($rows, $code) => [
                    'code' => $code,
                    'name' => Countries::name($code),
                    'flag' => Countries::flag($code),
                    'count' => (int) $rows->sum('total'),
                ])
                // ประเทศที่ถูกถอดออกจากทะเบียนแล้วยังค้างอยู่บนทริปเก่า แสดงไม่ได้
                ->filter(fn ($country) => $country['name'] !== null)
                ->sortByDesc('count')
                ->values()
                ->all();

            return [
                'total' => (int) $counts->sum('total'),
                'domestic' => [
                    'count' => (int) $domestic->sum('total'),
                    'regions' => $regions,
                ],
                'international' => [
                    'count' => (int) $international->sum('total'),
                    'countries' => $countries,
                ],
            ];
        });

        return $this->success($facets);
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

    public function featured(Request $request): JsonResponse
    {
        return TripListCache::respond('featured', $request, fn () => $this->buildFeatured());
    }

    private function buildFeatured(): JsonResponse
    {
        $trips = Trip::where('status', 'active')
            ->where('is_featured', true)
            ->with(['photos', 'schedules' => function ($q) {
                $q->where('departure_date', '>=', now()->startOfDay())->with('pickupPoints');
            }])
            ->orderByDesc('created_at')
            ->get();
        TripSchedule::syncBookedSeatsFor($trips->flatMap->schedules);
        Trip::loadListStats($trips);

        return $this->success(TripResource::collection($trips));
    }

    /**
     * Trips with at least one OPEN upcoming round that is almost full
     * (1–5 seats left). Sorted most-urgent first. Powers the "ใกล้เต็มแล้ว" rail.
     */
    public function almostFull(Request $request): JsonResponse
    {
        return TripListCache::respond('almost-full', $request, function () {
            $trips = $this->almostFullTrips();
            Trip::loadListStats($trips);

            return $this->success(TripResource::collection($trips));
        });
    }

    /**
     * Trips with at least one round on a live flash sale, soonest-ending first.
     * Powers the "⚡ Flash Sale" home rail and the flash-sale push CTA.
     */
    public function flashSale(Request $request): JsonResponse
    {
        return TripListCache::respond('flash-sale', $request, function () {
            $trips = $this->flashSaleTrips();
            Trip::loadListStats($trips);

            return $this->success(TripResource::collection($trips));
        });
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

        Trip::loadListStats($flash->concat($almost));

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

        TripSchedule::syncBookedSeatsFor($trips->flatMap->schedules);

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

        TripSchedule::syncBookedSeatsFor($trips->flatMap->schedules);

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
                    // ชุดเดียวกับ schedules() — รอบที่ฝังมากับหน้าทริปจึงใช้แทนการเรียก
                    // /schedules ซ้ำได้ (แอป/เว็บดูจาก key vehicle ว่าเซิร์ฟเวอร์ส่งครบแล้ว)
                    ->with(['vehicle', 'pickupPoints', 'vehicleOptions'])
                    // ที่นั่งที่กันไว้ให้คิวรอต้องไม่ถูกนับเป็นของว่างบนหน้าจอง
                    ->withHeldSeats()
                    ->orderBy('departure_date');
            }])
            ->firstOrFail();

        $this->registerView($trip, $request);

        // นับทุกรอบในคิวรีเดียว และเขียนเฉพาะรอบที่ตัวเลขเปลี่ยนจริง — เดิมเรียก
        // syncBookedSeats() ทีละรอบ ซึ่ง update() ทุกครั้ง แม้ค่าเท่าเดิม ทำให้ event
        // saved ไปล้าง TripListCache ทุกครั้งที่มีคนเปิดหน้าทริป
        TripSchedule::syncBookedSeatsFor($trip->schedules);
        // ราคาของรอบอ่าน $schedule->trip — ผูกสำเนาทริปที่ไม่มี relation (กันวงวน
        // trip → schedules → trip ตอน serialize) ให้ ไม่ต้องโหลดซ้ำรอบละครั้ง
        $lite = $trip->withoutRelations();
        $trip->schedules->each(fn ($s) => $s->setRelation('trip', $lite));
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

        $today = now()->startOfDay();

        // คิดคะแนนในฐานข้อมูลแล้วดึงมาแค่ 6 ใบ — เดิมโหลดทุกทริปในระบบพร้อมรูป รอบ
        // และจุดรับ แล้วจัดอันดับใน PHP ทุกครั้งที่มีคนเปิดหน้าทริป
        // ประเภท/ภาคที่เป็น null นับว่า "ตรงกัน" กับทริปที่เป็น null เหมือนเดิม (=== ของ PHP)
        [$typeSql, $typeBindings] = $trip->type === null
            ? ['CASE WHEN trips.type IS NULL THEN 3 ELSE 0 END', []]
            : ['CASE WHEN trips.type = ? THEN 3 ELSE 0 END', [$trip->type]];
        [$regionSql, $regionBindings] = $trip->region === null
            ? ['CASE WHEN trips.region IS NULL THEN 2 ELSE 0 END', []]
            : ['CASE WHEN trips.region = ? THEN 2 ELSE 0 END', [$trip->region]];

        $ranked = Trip::query()
            ->select('trips.*')
            ->where('status', 'active')
            ->where('id', '!=', $trip->id)
            ->orderByRaw(
                "({$typeSql}) + ({$regionSql})"
                .' + (CASE WHEN EXISTS (SELECT 1 FROM trip_schedules ts WHERE ts.trip_id = trips.id'
                .' AND ts.status = ? AND ts.departure_date >= ?) THEN 2 ELSE 0 END)'
                .' + (CASE WHEN trips.is_featured = ? THEN 1 ELSE 0 END) DESC',
                [...$typeBindings, ...$regionBindings, 'open', $today, true],
            )
            // คะแนนเท่ากันเรียงตาม id — ลำดับเดียวกับที่ sortByDesc เดิมได้จากแถวที่ดึงมา
            ->orderBy('trips.id')
            ->limit(6)
            ->with(['photos', 'schedules' => function ($q) use ($today) {
                $q->where('departure_date', '>=', $today)->with('pickupPoints');
            }])
            ->get();

        TripSchedule::syncBookedSeatsFor($ranked->flatMap->schedules);
        Trip::loadListStats($ranked);

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
            ->with(['vehicle', 'pickupPoints', 'vehicleOptions'])
            // ที่นั่งที่กันไว้ (คิวรอที่ได้สิทธิ์ / คนที่ถูกเลื่อนเพราะเหตุสุดวิสัย) ต้องไม่ถูก
            // นับเป็นของว่าง — ตัวเดียวกับหน้ารายละเอียดทริป
            ->withHeldSeats()
            ->orderBy('departure_date')
            ->get();
        TripSchedule::syncBookedSeatsFor($schedules);
        $lite = $trip->withoutRelations();
        $schedules->each(fn ($s) => $s->setRelation('trip', $lite));
        $schedules->each(fn ($s) => $this->weatherService->attach($s, $trip));

        // ผู้เรียกรู้ทริปจาก slug อยู่แล้ว — ไม่วาดทริปเต็มตัวซ้ำทุกรอบ (ต้องตรงกับ
        // รอบที่ฝังใน show() ทุกช่อง เพราะแอป/เว็บใช้แทนกันได้)
        return $this->success(tap(
            TripScheduleResource::collection($schedules),
            fn ($collection) => $collection->collection->each->withoutTrip(),
        ));
    }
}
