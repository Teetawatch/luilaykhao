<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\PaymentConfirmed;
use App\Events\SeatBooked;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreScheduleRequest;
use App\Http\Requests\Admin\StoreTripRequest;
use App\Http\Requests\Admin\StoreVehicleRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\DriverResource;
use App\Http\Resources\SchedulePickupPointResource;
use App\Http\Resources\ScheduleVehicleOptionResource;
use App\Http\Resources\TripResource;
use App\Http\Resources\TripScheduleResource;
use App\Http\Resources\VehicleResource;
use App\Jobs\NotifyTripCrewAssignedJob;
use App\Jobs\ProcessWaitlistJob;
use App\Jobs\SendStaffAssignmentPushJob;
use App\Jobs\VerifySlipJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingSeat;
use App\Models\CustomerIntake;
use App\Models\Driver;
use App\Models\GalleryImage;
use App\Models\HeroSlide;
use App\Models\InstallmentPayment;
use App\Models\Review;
use App\Models\SchedulePickupPoint;
use App\Models\ScheduleStaffAssignment;
use App\Models\ScheduleVehicleOption;
use App\Models\Setting;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePickupPoint;
use App\Services\BookingService;
use App\Services\ChatRoomEventService;
use App\Services\DriverLoginCodeService;
use App\Services\MailService;
use App\Services\RouteTrackService;
use App\Services\ScheduleSeatNotifier;
use App\Services\SlipOcrService;
use App\Services\SmsService;
use App\Services\VehicleDriverService;
use App\Support\MediaDisk;
use App\Support\PaymentQuote;
use App\Support\Polyline;
use App\Support\ThaiDate;
use App\Support\TripDocumentRequirements;
use App\Support\UrgentPopupSettings;
use App\Traits\ApiResponse;
use App\Traits\RemapsBookingPickup;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Guard as SpatieGuard;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminController extends Controller
{
    use ApiResponse, RemapsBookingPickup;

    public function __construct(
        private BookingService $bookingService,
        private MailService $mailService,
        private VehicleDriverService $vehicleDriverService,
        private DriverLoginCodeService $driverLoginCodes,
        private ScheduleSeatNotifier $seatNotifier,
    ) {}

    // ─── Dashboard Stats ──────────────────────────────────────

    public function dashboard(): JsonResponse
    {
        $totalTrips = Trip::count();
        $activeTrips = Trip::where('status', 'active')->count();

        $totalBookings = Booking::count();
        $pendingBookings = Booking::where('status', 'pending')->count();
        $confirmedBookings = Booking::where('status', 'confirmed')->count();
        $cancelledBookings = Booking::where('status', 'cancelled')->count();

        // Total Revenue: sum of paid_amount from all confirmed bookings
        $totalRevenue = Booking::whereIn('status', ['confirmed', 'completed'])->sum('paid_amount');

        // Monthly Revenue:
        // 1. Full payments made this month
        $monthlyFull = Booking::whereIn('status', ['confirmed', 'completed'])
            ->where('payment_type', 'full')
            ->whereMonth('transfer_datetime', now()->month)
            ->whereYear('transfer_datetime', now()->year)
            ->sum('total_amount');

        // 2. Installment payments made this month
        $monthlyInst = InstallmentPayment::where('status', 'paid')
            ->whereMonth('transfer_datetime', now()->month)
            ->whereYear('transfer_datetime', now()->year)
            ->sum('amount');

        $monthlyRevenue = $monthlyFull + $monthlyInst;

        $totalCustomers = User::role('customer')->count();
        $totalVehicles = Vehicle::count();

        $totalJoinTripBookings = Booking::where('is_join_trip', true)->count();
        $confirmedJoinTripBookings = Booking::where('is_join_trip', true)->where('status', 'confirmed')->count();

        $upcomingSchedules = TripSchedule::where('departure_date', '>=', now())
            ->where('status', 'open')
            ->count();

        $recentBookings = Booking::with(['schedule.trip', 'user', 'pickupPoint', 'installmentPayments', 'seats'])
            ->orderByDesc('created_at')
            ->take(5)
            ->get()
            ->map(fn ($b) => new BookingResource($b));

        // Monthly revenue chart data (last 6 months)
        $revenueChart = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $revenueChart[] = [
                'month' => $date->format('M Y'),
                'revenue' => (float) (
                    Booking::whereIn('status', ['confirmed', 'completed'])
                        ->where('payment_type', 'full')
                        ->whereMonth('transfer_datetime', $date->month)
                        ->whereYear('transfer_datetime', $date->year)
                        ->sum('total_amount')
                    +
                    InstallmentPayment::where('status', 'paid')
                        ->whereMonth('transfer_datetime', $date->month)
                        ->whereYear('transfer_datetime', $date->year)
                        ->sum('amount')
                ),
            ];
        }

        // Bookings by trip type
        $bookingsByType = Booking::selectRaw('trips.type, COUNT(*) as count')
            ->join('trip_schedules', 'bookings.schedule_id', '=', 'trip_schedules.id')
            ->join('trips', 'trip_schedules.trip_id', '=', 'trips.id')
            ->groupBy('trips.type')
            ->pluck('count', 'type');

        return $this->success([
            'total_trips' => $totalTrips,
            'active_trips' => $activeTrips,
            'total_bookings' => $totalBookings,
            'pending_bookings' => $pendingBookings,
            'confirmed_bookings' => $confirmedBookings,
            'cancelled_bookings' => $cancelledBookings,
            'total_revenue' => (float) $totalRevenue,
            'monthly_revenue' => (float) $monthlyRevenue,
            'total_customers' => $totalCustomers,
            'total_vehicles' => $totalVehicles,
            'upcoming_schedules' => $upcomingSchedules,
            'join_trip_bookings' => $totalJoinTripBookings,
            'confirmed_join_trip_bookings' => $confirmedJoinTripBookings,
            'recent_bookings' => $recentBookings,
            'revenue_chart' => $revenueChart,
            'bookings_by_type' => $bookingsByType,
        ]);
    }

    // ─── Trips ────────────────────────────────────────────────

    public function trips(Request $request): JsonResponse
    {
        $query = Trip::withCount('schedules');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->whereLike('title', "%{$request->search}%")
                    ->orWhereLike('location', "%{$request->search}%");
            });
        }

        $trips = $query->orderByDesc('created_at')->paginate($request->get('per_page', 15));

        return $this->paginated($trips->through(fn ($t) => new TripResource($t)));
    }

    public function showTrip(int $id): JsonResponse
    {
        $trip = Trip::findOrFail($id);

        return $this->success(new TripResource($trip));
    }

    public function storeTrip(StoreTripRequest $request): JsonResponse
    {
        $data = $this->withNormalizedDocuments($request->validated());
        $slugBase = Str::slug($data['title']);
        if (empty($slugBase)) {
            $slugBase = 'trip-'.Str::random(3);
        }
        $data['slug'] = $slugBase.'-'.Str::lower(Str::random(5));

        $trip = Trip::create($data);

        return $this->success(new TripResource($trip), 'สร้างทริปสำเร็จ', 201);
    }

    public function updateTrip(StoreTripRequest $request, int $id): JsonResponse
    {
        $trip = Trip::findOrFail($id);
        $trip->update($this->withNormalizedDocuments($request->validated()));

        return $this->success(new TripResource($trip->fresh()), 'อัปเดตทริปสำเร็จ');
    }

    /**
     * เขียน `key` ของเอกสารแนบลงฐานข้อมูลเสมอ
     *
     * key เป็นตัวผูกไฟล์ที่ลูกค้าส่งมาแล้วกับข้อกำหนด ถ้าปล่อยให้คำนวณจากชื่อ
     * ตอนอ่าน วันที่แอดมินแก้ชื่อเอกสารคือวันที่ไฟล์เก่าทั้งกองหลุดจากช่องของมัน
     */
    private function withNormalizedDocuments(array $data): array
    {
        if (array_key_exists('document_requirements', $data)) {
            $data['document_requirements'] = TripDocumentRequirements::normalize($data['document_requirements']);
        }

        return $data;
    }

    public function bulkUpdateTripField(Request $request): JsonResponse
    {
        $request->validate([
            'trip_ids' => ['required', 'array'],
            'trip_ids.*' => ['exists:trips,id'],
            'field' => ['required', 'string', 'in:highlights,itinerary,preparations,inclusions,exclusions,must_know'],
            'value' => ['required'],
        ]);

        $trips = Trip::whereIn('id', $request->trip_ids)->get();
        foreach ($trips as $trip) {
            $trip->update([
                $request->field => $request->value,
            ]);
        }

        return $this->success(null, 'อัปเดตข้อมูลทริปที่เลือกสำเร็จ');
    }

    public function deleteTrip(int $id): JsonResponse
    {
        $trip = Trip::findOrFail($id);

        // check if trip has any confirmed bookings
        $hasBookings = Booking::whereHas('schedule', fn ($q) => $q->where('trip_id', $id))
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists();

        if ($hasBookings) {
            return $this->error('ไม่สามารถลบทริปที่มีการจองอยู่', 422);
        }

        $trip->schedules()->delete();
        $trip->delete();

        return $this->success(null, 'ลบทริปสำเร็จ');
    }

    /**
     * อัปโหลดไฟล์ GPX ของเส้นทางเดิน — แปลงเป็นโปรไฟล์ความชันเก็บไว้ที่ทริป
     * และเติมระยะทาง/ความสูงสะสมให้อัตโนมัติถ้ายังไม่ได้กรอกไว้ เพราะค่าที่วัด
     * จากเส้นทางจริงแม่นกว่าที่คนกรอกเอง
     */
    public function uploadTripRouteTrack(int $id, Request $request, RouteTrackService $routeTracks): JsonResponse
    {
        $request->validate([
            // GPX เป็น XML — บาง OS ส่ง mime เป็น text/xml, application/xml หรือ
            // application/octet-stream จึงตรวจที่นามสกุลแทน
            'gpx' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('gpx');

        if (strtolower($file->getClientOriginalExtension()) !== 'gpx') {
            return $this->error('รองรับเฉพาะไฟล์ .gpx เท่านั้น', 422);
        }

        $trip = Trip::findOrFail($id);

        try {
            $track = $routeTracks->fromGpx((string) file_get_contents($file->getRealPath()));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $trip->route_track = $track;

        if ($trip->distance_km === null && $track['distance_km'] > 0) {
            $trip->distance_km = $track['distance_km'];
        }
        if ($trip->elevation_gain_m === null && $track['elevation_gain_m'] > 0) {
            $trip->elevation_gain_m = $track['elevation_gain_m'];
        }

        $trip->save();

        return $this->success([
            'route_track' => $track,
            'distance_km' => (float) $trip->distance_km,
            'elevation_gain_m' => $trip->elevation_gain_m,
        ], 'อัปโหลดเส้นทางสำเร็จ');
    }

    public function deleteTripRouteTrack(int $id): JsonResponse
    {
        $trip = Trip::findOrFail($id);
        $trip->route_track = null;
        $trip->save();

        return $this->success(null, 'ลบเส้นทางสำเร็จ');
    }

    // ─── Schedules ────────────────────────────────────────────

    public function schedules(Request $request): JsonResponse
    {
        $query = TripSchedule::with(['trip', 'vehicle', 'pickupPoints', 'vehicleOptions']);

        $query->withCount([
            'bookings as active_bookings_count' => fn ($q) => $q->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES),
            'waitlistEntries as waitlist_count' => fn ($q) => $q->whereIn('status', ['waiting', 'offered']),
            'photos as photos_count',
        ]);

        if (Schema::hasTable('schedule_staff_assignments')) {
            $query->withCount('activeStaff as assigned_staff_count');
        }

        if ($request->filled('trip_id')) {
            $query->where('trip_id', $request->trip_id);
        }
        if ($request->filled('search')) {
            $query->whereHas('trip', fn ($q) => $q->whereLike('title', "%{$request->search}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('upcoming')) {
            $query->where('departure_date', '>=', now()->startOfDay());
        }
        if ($request->filled('from')) {
            $query->whereDate('departure_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('departure_date', '<=', $request->to);
        }

        // เรียงตามวันเดินทางใหม่→เก่า และตัดสินเสมอด้วย id เพื่อให้ลำดับคงที่
        // ข้ามหน้า (หลายรอบมีวันเดินทางวันเดียวกันได้ ถ้าไม่มีตัวตัดสิน แถวเดิม
        // อาจโผล่ซ้ำหรือหายไปเมื่อไล่ดูทีละหน้า)
        //
        // order=asc สำหรับหน้าที่ให้ "เลือกรอบข้างหน้า" — เรียงใหม่→เก่าแล้วตัดหน้าแรก
        // จะได้รอบที่ไกลที่สุดมาก่อน ซึ่งตรงข้ามกับที่ตัวเลือกควรเห็น
        $direction = $request->input('order') === 'asc' ? 'asc' : 'desc';
        $schedules = $query
            ->orderBy('departure_date', $direction)
            ->orderBy('id', $direction)
            ->paginate($request->get('per_page', 15));
        $schedules->getCollection()->each->syncBookedSeats();

        return $this->paginated($schedules->through(fn ($s) => new TripScheduleResource($s)));
    }

    public function storeSchedule(StoreScheduleRequest $request): JsonResponse
    {
        $schedule = TripSchedule::create($request->validated());

        // คัดลอกจุดรับจากรอบล่าสุดของทริปเดียวกันให้อัตโนมัติ (ราคา/เวลานัดติดมาด้วย)
        $seeded = $this->autoSeedPickupPoints($schedule);

        $message = $seeded > 0
            ? "สร้างรอบเดินทางสำเร็จ — คัดลอกจุดรับ {$seeded} จุดจากรอบก่อนหน้าให้อัตโนมัติ"
            : 'สร้างรอบเดินทางสำเร็จ';

        return $this->success(
            new TripScheduleResource($schedule->load('trip', 'vehicle', 'pickupPoints', 'vehicleOptions')),
            $message,
            201,
        );
    }

    /**
     * เติมจุดรับให้รอบใหม่จากรอบล่าสุดของทริปเดียวกันที่มีจุดรับอยู่ — คืนจำนวนที่คัดลอก
     */
    private function autoSeedPickupPoints(TripSchedule $schedule): int
    {
        if ($schedule->pickupPoints()->exists()) {
            return 0;
        }

        $source = TripSchedule::where('trip_id', $schedule->trip_id)
            ->where('id', '!=', $schedule->id)
            ->whereHas('pickupPoints')
            ->with('pickupPoints')
            ->orderByDesc('departure_date')
            ->orderByDesc('id')
            ->first();

        return $source ? $this->clonePickupPoints($source, $schedule) : 0;
    }

    /**
     * คัดลอกจุดรับจากรอบต้นทางไปยังรอบปลายทาง ข้ามจุดที่ซ้ำ (ภูมิภาค+ชื่อจุด) — คืนจำนวนที่คัดลอกจริง
     */
    private function clonePickupPoints(TripSchedule $source, TripSchedule $target): int
    {
        $existing = $target->pickupPoints()
            ->get(['region', 'pickup_location'])
            ->map(fn ($p) => $p->region.'|'.$p->pickup_location)
            ->flip();

        $copied = 0;
        foreach ($source->pickupPoints as $point) {
            $key = $point->region.'|'.$point->pickup_location;
            if ($existing->has($key)) {
                continue;
            }

            SchedulePickupPoint::create([
                'schedule_id' => $target->id,
                'region' => $point->region,
                'region_label' => $point->region_label,
                'pickup_location' => $point->pickup_location,
                'price' => $point->price,
                'map_url' => $point->map_url,
                'image_url' => $point->image_url,
                'latitude' => $point->latitude,
                'longitude' => $point->longitude,
                'notes' => $point->notes,
                'pickup_time' => $point->pickup_time,
                'sort_order' => $point->sort_order,
            ]);

            $existing->put($key, true);
            $copied++;
        }

        return $copied;
    }

    public function updateSchedule(Request $request, int $id): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($id);

        $validated = $request->validate([
            'departure_date' => ['sometimes', 'date'],
            'departs_at' => ['sometimes', 'nullable', 'date'],
            'return_date' => ['sometimes', 'date'],
            'total_seats' => ['sometimes', 'integer', 'min:1'],
            'transport_type' => ['sometimes', Rule::in(TripSchedule::TRANSPORT_TYPES)],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'status' => ['sometimes', 'in:open,closed,full,cancelled'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'deposit_enabled' => ['nullable', 'boolean'],
            'deposit_type' => ['nullable', 'in:amount,percent'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'deposit_percent' => ['nullable', 'integer', 'min:1', 'max:99'],
            'join_trip_enabled' => ['nullable', 'boolean'],
            'join_trip_price' => ['nullable', 'numeric', 'min:0'],
            // เพดานคนจอยทริป — ไม่ส่ง/ส่ง null = ไม่จำกัด
            'join_trip_seats' => ['nullable', 'integer', 'min:1', 'max:500'],
            'is_charter' => ['nullable', 'boolean'],
            'flash_sale_enabled' => ['sometimes', 'boolean'],
            'flash_sale_price' => ['nullable', 'numeric', 'min:0', 'required_if:flash_sale_enabled,true'],
            'flash_sale_starts_at' => ['nullable', 'date'],
            'flash_sale_ends_at' => ['nullable', 'date', 'after:now', 'after:flash_sale_starts_at'],
            // ข้อมูลเที่ยวบินมักถูกกรอกที่หน้านี้ ไม่ใช่ตอนสร้างรอบ (กว่าจะออกตั๋วจริง
            // ก็เปิดขายไปแล้วหลายสัปดาห์) — กฎเดียวกับตอนสร้าง
            ...StoreScheduleRequest::flightPlanRules(),
        ]);

        $schedule->update($validated);

        return $this->success(
            new TripScheduleResource($schedule->fresh()->load('trip', 'vehicle')),
            'อัปเดตรอบเดินทางสำเร็จ',
        );
    }

    public function bulkUpdateSchedules(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:trip_schedules,id'],
            'data' => ['required', 'array'],
        ]);

        TripSchedule::whereIn('id', $request->ids)->update($request->data);

        return $this->success(null, 'อัปเดตรอบเดินทางสำเร็จ');
    }

    public function deleteSchedule(int $id): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($id);

        $hasBookings = $schedule->bookings()
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists();

        if ($hasBookings) {
            return $this->error('ไม่สามารถลบรอบที่มีการจองอยู่', 422);
        }

        $schedule->delete();

        return $this->success(null, 'ลบรอบเดินทางสำเร็จ');
    }

    public function moveBookings(Request $request): JsonResponse
    {
        $request->validate([
            'source_schedule_id' => ['required', 'exists:trip_schedules,id'],
            'target_schedule_id' => ['required', 'exists:trip_schedules,id'],
            'passenger_ids' => ['nullable', 'array'],
            'passenger_ids.*' => ['integer', 'exists:booking_passengers,id'],
            'seat_assignments' => ['nullable', 'array'],
            'seat_assignments.*' => ['nullable', 'string', 'max:30'],
        ]);

        $source = TripSchedule::with(['bookings.passengers', 'bookings.seats', 'bookings.installmentPayments', 'pickupPoints'])->findOrFail($request->source_schedule_id);
        $target = TripSchedule::with('pickupPoints')->findOrFail($request->target_schedule_id);
        $sameSchedule = (int) $source->id === (int) $target->id;
        $source->syncBookedSeats();
        $target->syncBookedSeats();
        $bookedBeforeMove = (int) $target->booked_seats;
        $sourceBookedBeforeMove = (int) $source->booked_seats;

        $bookings = $source->bookings->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES);
        $selectedPassengerIds = collect($request->input('passenger_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($selectedPassengerIds->isEmpty()) {
            $selectedPassengerIds = $bookings
                ->flatMap(fn ($booking) => $booking->passengers->pluck('id'))
                ->values();
        }

        $seatAssignments = collect($request->input('seat_assignments', []))
            ->mapWithKeys(fn ($seatId, $passengerId) => [(int) $passengerId => trim((string) $seatId)])
            ->filter(fn ($seatId, $passengerId) => $seatId !== '' && $selectedPassengerIds->contains((int) $passengerId));

        // รอบปลายทางที่บินไปไม่มีผังที่นั่งของเรา — เบอร์ที่นั่งบนรถตู้ไม่มีความหมาย
        // บนเครื่อง ปล่อยคืนไปเลยดีกว่าลากตัวเลขเดิมข้ามไปให้ลูกค้าเห็นผิด ๆ
        // แล้วทีมงานค่อยกรอกเลขที่นั่งจริงจากสายการบินให้ทีหลัง
        $dropSeats = ! $sameSchedule && ! $target->allowsSeatSelection();
        if ($dropSeats) {
            $seatAssignments = collect();
        }

        $bookings = $bookings
            ->filter(fn ($booking) => $booking->passengers->pluck('id')->intersect($selectedPassengerIds)->isNotEmpty())
            ->values();
        $bookingsCount = $bookings->count();

        if ($bookingsCount === 0) {
            return $this->error('ไม่พบผู้โดยสารที่เลือกในรอบต้นทาง', 422);
        }

        $totalPassengers = $bookings->sum(fn ($booking) => $booking->passengers->pluck('id')->intersect($selectedPassengerIds)->count());
        $seatPassengers = $bookings->sum(function ($booking) use ($selectedPassengerIds) {
            if ($booking->is_join_trip) {
                return 0;
            }

            return $booking->passengers->pluck('id')->intersect($selectedPassengerIds)->count();
        });

        // Check capacity if not join trip
        if (! $sameSchedule && $seatPassengers > 0 && $target->available_seats < $seatPassengers) {
            return $this->error("ที่นั่งในรอบปลายทางไม่เพียงพอ (ต้องการ $seatPassengers, ว่าง {$target->available_seats})", 422);
        }

        // Prepare pickup point mapping
        $pickupMap = $this->pickupPointMap($source, $target);

        // ประเภทรถผูกกับรอบ — ใบที่ย้ายไปหาคันชื่อเดียวกันในรอบปลายทาง ไม่เจอก็ไป
        // อยู่กองรถคันเดียวของรอบนั้น (0) ที่นั่งของแต่ละใบจึงตรวจกันคนละกอง
        $targetOptionIds = $bookings
            ->mapWithKeys(fn ($booking) => [
                $booking->id => (int) ($target->vehicleOptionByLabel($booking->vehicle_option_label)?->id ?? 0),
            ]);
        $targetOptionIdFor = fn (Booking $booking) => (int) ($targetOptionIds[$booking->id] ?? 0);

        $seatMoves = $dropSeats
            ? collect()
            : $bookings
                ->flatMap(fn ($booking) => $this->seatMovesForBooking($booking, $selectedPassengerIds, $seatAssignments)
                    ->map(fn ($move) => [...$move, 'target_option_id' => $targetOptionIdFor($booking)]))
                ->values();

        $seatIdsToMove = $seatMoves
            ->pluck('target_seat_id')
            ->filter()
            ->values();

        // ซ้ำกันเฉพาะเมื่ออยู่คันเดียวกัน — A1 ของบัสกับ A1 ของตู้เป็นคนละที่นั่ง
        $duplicateSeatIds = $seatMoves
            ->filter(fn ($move) => filled($move['target_seat_id']))
            ->groupBy(fn ($move) => $move['target_option_id'].':'.$move['target_seat_id'])
            ->filter(fn ($group) => $group->count() > 1)
            ->map(fn ($group) => $group->first()['target_seat_id'])
            ->unique()
            ->values();

        if ($duplicateSeatIds->isNotEmpty()) {
            return $this->error('เลือกที่นั่งปลายทางซ้ำ: '.$duplicateSeatIds->join(', '), 422);
        }

        if ($seatIdsToMove->isNotEmpty()) {
            $movingSeatRowIds = $seatMoves
                ->map(fn ($move) => $move['seat']->id)
                ->unique()
                ->values();

            $occupiedSeatIds = collect($seatMoves)
                ->filter(fn ($move) => filled($move['target_seat_id']))
                ->groupBy('target_option_id')
                ->flatMap(function ($moves, $optionId) use ($target, $sameSchedule, $movingSeatRowIds) {
                    $query = BookingSeat::where('schedule_id', $target->id)
                        ->where('vehicle_option_id', (int) $optionId)
                        ->whereIn('seat_id', $moves->pluck('target_seat_id')->unique()->all());

                    if ($sameSchedule) {
                        $query->whereNotIn('id', $movingSeatRowIds);
                    }

                    return $query->pluck('seat_id');
                })
                ->unique()
                ->values();

            if ($occupiedSeatIds->isNotEmpty()) {
                return $this->error('ที่นั่ง '.$occupiedSeatIds->join(', ').' ในรอบปลายทางถูกจองแล้ว กรุณาเลือกปลายทางอื่นหรือแก้ผังที่นั่งก่อน', 422);
            }
        }

        DB::transaction(function () use ($source, $target, $sameSchedule, $bookings, $pickupMap, $selectedPassengerIds, $seatAssignments, $dropSeats, $targetOptionIdFor) {
            foreach ($bookings as $booking) {
                $selectedInBooking = $booking->passengers
                    ->whereIn('id', $selectedPassengerIds->all())
                    ->values();
                $seatMoves = $this->seatMovesForBooking($booking, $selectedPassengerIds, $seatAssignments);

                if ($dropSeats) {
                    // ย้ายไปรอบที่บินไป — คืนที่นั่งเดิมให้รอบต้นทาง ไม่ลากไปด้วย
                    $seatMoves->each(fn ($move) => $move['seat']->delete());
                    $seatMoves = collect();
                }

                if ($sameSchedule) {
                    $seatMoves->each(fn ($move) => $move['seat']->update([
                        'seat_id' => $move['target_seat_id'],
                    ]));

                    continue;
                }

                if ($selectedInBooking->count() === $booking->passengers->count()) {
                    $updateData = array_merge(
                        [
                            'schedule_id' => $target->id,
                            'vehicle_option_id' => $targetOptionIdFor($booking) ?: null,
                        ],
                        $this->resolveMovedPickup($booking, $source, $target, $pickupMap),
                    );

                    $booking->update($updateData);

                    // จุดรับรายคนก็ผูกกับรอบเดิม ต้องย้ายตามด้วย ไม่งั้นสตาฟจะเห็นเวลารับของทริปเดิม
                    $this->remapPassengerPickupPoints($booking, $pickupMap);

                    $seatMoves->each(fn ($move) => $move['seat']->update([
                        'schedule_id' => $target->id,
                        'vehicle_option_id' => $targetOptionIdFor($booking),
                        'seat_id' => $move['target_seat_id'],
                    ]));
                } else {
                    $newBooking = $this->splitBookingForMove($booking, $selectedInBooking, $source, $target, $pickupMap);

                    $selectedInBooking
                        ->each(fn ($passenger) => $passenger->update([
                            'booking_id' => $newBooking->id,
                            'pickup_point_id' => $passenger->pickup_point_id
                                ? ($pickupMap[$passenger->pickup_point_id] ?? null)
                                : null,
                        ]));

                    $seatMoves
                        ->each(fn ($move) => $move['seat']->update([
                            'booking_id' => $newBooking->id,
                            'schedule_id' => $target->id,
                            'vehicle_option_id' => $targetOptionIdFor($booking),
                            'seat_id' => $move['target_seat_id'],
                        ]));
                }
            }

            $source->syncBookedSeats();
            $target->syncBookedSeats();
            $source->syncVehicleOptionSeats();
            $target->syncVehicleOptionSeats();
        });

        // ที่นั่งรอบปลายทางเพิ่มขึ้นเหมือนมีคนจอง — ต้องแจ้งเตือนเหมือนกัน (เต็ม /
        // เหลือน้อย / ข้ามแถบสถานะการันตี) เดิมย้ายจนรอบเต็มแล้วเงียบสนิท
        if (! $sameSchedule) {
            $this->seatNotifier->seatsIncreased(
                $target->id,
                $bookedBeforeMove,
                (int) $target->fresh()->booked_seats,
            );

            // ...และรอบต้นทางก็ได้ที่นั่งคืนมาเหมือนมีคนยกเลิก
            ProcessWaitlistJob::dispatch($source->id);
            $this->seatNotifier->seatsFreed(
                $source->id,
                $sourceBookedBeforeMove,
                (int) $source->fresh()->booked_seats,
            );
        }

        return $this->success(null, "ย้ายผู้โดยสาร $totalPassengers ท่าน จาก $bookingsCount รายการจอง ไปยังรอบเดินทางวันที่ ".ThaiDate::full($target->departure_date).' สำเร็จ');
    }

    private function seatMovesForBooking(Booking $booking, $selectedPassengerIds, $seatAssignments)
    {
        $selectedIds = collect($selectedPassengerIds)->map(fn ($id) => (int) $id);
        $assignments = collect($seatAssignments)->mapWithKeys(fn ($seatId, $passengerId) => [(int) $passengerId => $seatId]);
        $orderedPassengers = $booking->passengers->sortBy('id')->values();
        $orderedSeats = $booking->seats->sortBy('id')->values();

        return $orderedPassengers
            ->map(function ($passenger, $index) use ($selectedIds, $orderedSeats) {
                if (! $selectedIds->contains((int) $passenger->id)) {
                    return null;
                }

                $seatByName = $orderedSeats->firstWhere('passenger_name', $passenger->name);

                $seat = $seatByName ?: $orderedSeats->get($index);

                if (! $seat) {
                    return null;
                }

                return [
                    'passenger_id' => (int) $passenger->id,
                    'seat' => $seat,
                ];
            })
            ->filter()
            ->unique(fn ($move) => $move['seat']->id)
            ->map(function ($move) use ($assignments) {
                $move['target_seat_id'] = $assignments->get($move['passenger_id'], $move['seat']->seat_id);

                return $move;
            })
            ->values();
    }

    private function splitBookingForMove(Booking $booking, $selectedPassengers, TripSchedule $source, TripSchedule $target, array $pickupMap): Booking
    {
        $originalPassengerCount = max(1, $booking->passengers->count());
        $selectedCount = max(1, $selectedPassengers->count());
        $ratio = $selectedCount / $originalPassengerCount;

        $movedPickup = $this->resolveMovedPickup($booking, $source, $target, $pickupMap);

        // คอลัมน์ที่ unique ทั้งหมดต้องไม่ถูกคัดลอก ไม่งั้น insert ชน unique index (500)
        // token เหล่านี้สร้างเมื่อถูกเรียกใช้ครั้งแรกอยู่แล้ว (ensureShareToken ฯลฯ) จึงปล่อยว่างได้
        $newBooking = $booking->replicate([
            'booking_ref',
            'qr_code',
            'share_token',
            'payment_token',
            'birthdate_token',
            'gift_code',
            'created_at',
            'updated_at',
            'checked_in',
            'checked_in_at',
        ]);

        $newBooking->fill(array_merge([
            'booking_ref' => Booking::generateRef(),
            'qr_code' => Booking::generateQrCode(),
            'share_token' => null,
            'payment_token' => null,
            'birthdate_token' => null,
            'gift_code' => null,
            'schedule_id' => $target->id,
            // ประเภทรถของรอบปลายทาง — ใบที่แยกออกมาไปคนละรอบแล้ว จะชี้คันของรอบเดิมไม่ได้
            'vehicle_option_id' => $target->vehicleOptionByLabel($booking->vehicle_option_label)?->id,
            'is_group' => $selectedCount > 1,
            'total_amount' => round(((float) $booking->total_amount) * $ratio, 2),
            'paid_amount' => round(((float) $booking->paid_amount) * $ratio, 2),
            'discount_amount' => round(((float) $booking->discount_amount) * $ratio, 2),
        ], $movedPickup));
        $newBooking->save();

        $booking->update([
            'is_group' => ($originalPassengerCount - $selectedCount) > 1,
            'total_amount' => max(0, round(((float) $booking->total_amount) - ((float) $newBooking->total_amount), 2)),
            'paid_amount' => max(0, round(((float) $booking->paid_amount) - ((float) $newBooking->paid_amount), 2)),
            'discount_amount' => max(0, round(((float) $booking->discount_amount) - ((float) $newBooking->discount_amount), 2)),
        ]);

        foreach ($booking->installmentPayments as $payment) {
            $newAmount = round(((float) $payment->amount) * $ratio, 2);
            $payment->replicate(['created_at', 'updated_at'])->fill([
                'booking_id' => $newBooking->id,
                'amount' => $newAmount,
            ])->save();

            $payment->update([
                'amount' => max(0, round(((float) $payment->amount) - $newAmount, 2)),
            ]);
        }

        return $newBooking;
    }

    public function scheduleStaff(int $id): JsonResponse
    {
        $schedule = TripSchedule::with(['trip', 'vehicle'])
            ->withCount([
                'bookings as active_bookings_count' => fn ($q) => $q->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES),
            ])
            ->findOrFail($id);

        if (! Schema::hasTable('schedule_staff_assignments')) {
            return $this->success($this->formatScheduleStaffPayload($schedule));
        }

        $this->loadScheduleStaffRelations($schedule);

        return $this->success($this->formatScheduleStaffPayload($schedule));
    }

    /**
     * ปลดสตาฟทุกคนออกจากรอบนี้พร้อมกัน — ปุ่ม "รีเซ็ตสตาฟ" หลังจบทริป สำหรับรอบ
     * ที่แอดมินอยากเคลียร์เองก่อน ReleaseEndedTripStaffJob จะกวาดตอนตีสาม
     */
    public function releaseScheduleStaff(int $id): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($id);

        if (! Schema::hasTable('schedule_staff_assignments')) {
            return $this->error('ยังไม่ได้ตั้งค่าตารางมอบหมายสตาฟในระบบ', 422);
        }

        $released = ScheduleStaffAssignment::where('schedule_id', $schedule->id)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);

        $schedule->load(['trip', 'vehicle']);
        $schedule->loadCount([
            'bookings as active_bookings_count' => fn ($q) => $q->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES),
        ]);
        $this->loadScheduleStaffRelations($schedule);

        return $this->success(
            $this->formatScheduleStaffPayload($schedule),
            $released > 0 ? "ปลดสตาฟออกจากรอบนี้แล้ว {$released} คน" : 'รอบนี้ไม่มีสตาฟค้างอยู่แล้ว',
        );
    }

    private function loadScheduleStaffRelations(TripSchedule $schedule): void
    {
        $withStats = function ($query) {
            // roles come along so the payload can flag anyone on the round who no
            // longer holds the staff role — otherwise they are invisible in the UI.
            $query->with('roles')->withCount('assignedSchedules');

            if (Schema::hasTable('staff_reviews')) {
                $query->withCount('staffReviewsReceived')
                    ->withAvg('staffReviewsReceived as avg_staff_rating', 'rating');
            }
        };

        $schedule->loadCount('activeStaff as assigned_staff_count');
        $schedule->load([
            'activeStaff' => $withStats,
            'releasedStaff' => $withStats,
        ]);
    }

    public function syncScheduleStaff(Request $request, int $id): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($id);

        if (! Schema::hasTable('schedule_staff_assignments')) {
            return $this->error('ยังไม่ได้ตั้งค่าตารางมอบหมายสตาฟในระบบ', 422);
        }

        $validated = $request->validate([
            'staff_ids' => ['nullable', 'array'],
            'staff_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);

        $staffIds = collect($validated['staff_ids'] ?? [])->values();

        // Capture who was already on this round so we only push to the staff
        // who are *newly* assigned (not re-notifying existing ones or anyone removed).
        $existingStaffIds = $schedule->activeStaff()->pluck('users.id')->map(fn ($id) => (int) $id);
        $wantedStaffIds = $staffIds->map(fn ($id) => (int) $id);

        // Only the people being *added* need the staff role. Someone already on the
        // round whose role changed afterwards must not block every later edit of it —
        // the admin can still see them on the round and take them off.
        $addedStaffIds = $wantedStaffIds->diff($existingStaffIds)->values();

        if ($addedStaffIds->isNotEmpty()) {
            $this->ensureRoleNameExists('staff');

            $validStaffIds = $this->usersWithRoleName('staff')
                ->whereIn('id', $addedStaffIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id);

            $missingRoleIds = $addedStaffIds->diff($validStaffIds);

            if ($missingRoleIds->isNotEmpty()) {
                $names = User::whereIn('id', $missingRoleIds)->pluck('name')->implode(', ');

                return $this->error(
                    $names !== ''
                        ? "เพิ่ม {$names} ไม่ได้ เพราะบัญชีนี้ยังไม่ได้ตั้งสิทธิ์เป็นสตาฟ — แก้สิทธิ์ที่หน้าผู้ใช้งานก่อน"
                        : 'พบผู้ใช้ที่ไม่ได้เป็นสิทธิ์ staff',
                    422,
                );
            }
        }

        // Detaching would drop the row a released assignment lives in, taking the
        // record of who worked the round with it — so only touch active rows here
        // and revive released ones by clearing released_at.
        $schedule->activeStaff()->detach($existingStaffIds->diff($wantedStaffIds)->all());

        foreach ($wantedStaffIds->diff($existingStaffIds) as $staffId) {
            ScheduleStaffAssignment::updateOrCreate(
                ['schedule_id' => $schedule->id, 'user_id' => $staffId],
                ['assigned_by' => $request->user()->id, 'released_at' => null],
            );
        }

        $newStaffIds = $wantedStaffIds->diff($existingStaffIds)->values();

        if ($newStaffIds->isNotEmpty()) {
            SendStaffAssignmentPushJob::dispatch(
                $schedule->id,
                $newStaffIds->all(),
                $request->user()->id,
            );

            // ลูกค้าในรอบก็ควรรู้ว่ามีสตาฟประจำรอบแล้ว (พร้อมเบอร์ในใบจอง)
            NotifyTripCrewAssignedJob::dispatch(
                $schedule->id,
                NotifyTripCrewAssignedJob::KIND_STAFF,
            );

            // แนะนำตัวทีมงานในห้องแชทของรอบด้วย ลูกค้าจะได้รู้ว่าทักใครได้
            $chatEvents = app(ChatRoomEventService::class);
            foreach (User::whereIn('id', $newStaffIds->all())->get() as $staff) {
                $chatEvents->staffAssigned($schedule, $staff);
            }
        }
        $schedule->load(['trip', 'vehicle']);
        $schedule->loadCount([
            'bookings as active_bookings_count' => fn ($q) => $q->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES),
        ]);
        $this->loadScheduleStaffRelations($schedule);

        return $this->success($this->formatScheduleStaffPayload($schedule), 'อัปเดตรายชื่อสตาฟประจำรอบสำเร็จ');
    }

    private function formatScheduleStaffPayload(TripSchedule $schedule): array
    {
        return [
            'schedule' => [
                'id' => $schedule->id,
                'trip_id' => $schedule->trip_id,
                'trip_title' => $schedule->trip?->title,
                'trip_location' => $schedule->trip?->location,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'return_date' => $schedule->return_date?->toDateString(),
                'status' => $schedule->status,
                'transport_type' => $schedule->transport_type,
                'vehicle' => $schedule->vehicle ? [
                    'id' => $schedule->vehicle->id,
                    'name' => $schedule->vehicle->name,
                    'type' => $schedule->vehicle->type,
                    'license_plate' => $schedule->vehicle->license_plate,
                ] : null,
                'total_seats' => (int) $schedule->total_seats,
                'booked_seats' => (int) $schedule->booked_seats,
                'available_seats' => (int) $schedule->available_seats,
                'active_bookings_count' => (int) ($schedule->active_bookings_count ?? 0),
                'assigned_staff_count' => (int) ($schedule->assigned_staff_count
                    ?? ($schedule->relationLoaded('activeStaff') ? $schedule->activeStaff->count() : 0)),
            ],
            'staff' => $schedule->relationLoaded('activeStaff')
                ? $schedule->activeStaff->map(fn ($user) => $this->formatScheduleStaffMember($user))->values()
                : [],
            'released_staff' => $schedule->relationLoaded('releasedStaff')
                ? $schedule->releasedStaff->map(fn ($user) => $this->formatScheduleStaffMember($user))->values()
                : [],
        ];
    }

    private function formatScheduleStaffMember(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'nickname' => $user->nickname,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar_url' => $user->avatar_url,
            'assigned_schedules_count' => (int) ($user->assigned_schedules_count ?? 0),
            'total_staff_reviews' => (int) ($user->staff_reviews_received_count ?? 0),
            'avg_staff_rating' => $user->avg_staff_rating ? round((float) $user->avg_staff_rating, 2) : null,
            'has_staff_role' => $user->relationLoaded('roles')
                ? $user->roles->contains('name', 'staff')
                : true,
            'assigned_at' => $user->pivot?->created_at?->toISOString(),
            'released_at' => $user->pivot?->released_at
                ? Carbon::parse($user->pivot->released_at)->toISOString()
                : null,
        ];
    }

    private function usersWithRoleName(string $roleName)
    {
        return User::whereHas('roles', fn ($query) => $query->where('name', $roleName));
    }

    private function ensureRoleNameExists(string $roleName): void
    {
        if (Role::where('name', $roleName)->exists()) {
            return;
        }

        $this->ensureAssignableRole($roleName);
    }

    private function ensureAssignableRole(string $roleName): Role
    {
        $role = Role::firstOrCreate([
            'name' => $roleName,
            'guard_name' => SpatieGuard::getDefaultName(User::class),
        ]);

        if ($role->wasRecentlyCreated) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $role;
    }

    // ─── Bookings ─────────────────────────────────────────────

    public function bookings(Request $request): JsonResponse
    {
        $query = Booking::with([
            'schedule.trip',
            'schedule.vehicle',
            'schedule.pickupPoints',
            'schedule.staff',
            'user',
            'passengers',
            'seats',
            'installmentPayments',
            'pickupPoint',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->date);
        }
        if ($request->filled('payment_type')) {
            $query->where('payment_type', $request->payment_type);
        }
        if ($request->filled('custom_pickup_status')) {
            $query->where('custom_pickup_status', $request->custom_pickup_status);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->whereLike('booking_ref', "%{$request->search}%")
                    ->orWhereHas('user', fn ($u) => $u->whereLike('name', "%{$request->search}%")
                        ->orWhereLike('email', "%{$request->search}%")
                        ->orWhereLike('phone', "%{$request->search}%"))
                    ->orWhereHas('passengers', fn ($p) => $p->whereLike('name', "%{$request->search}%")
                        ->orWhereLike('phone', "%{$request->search}%"))
                    ->orWhereHas('schedule.trip', fn ($trip) => $trip->whereLike('title', "%{$request->search}%"));
            });
        }
        if ($request->filled('booking_type')) {
            if ($request->booking_type === 'join_trip') {
                $query->where('is_join_trip', true);
            } elseif ($request->booking_type === 'regular') {
                $query->where(function ($q) {
                    $q->where('is_join_trip', false)->orWhereNull('is_join_trip');
                });
            }
        }

        $bookings = $query->orderByDesc('created_at')->paginate($request->get('per_page', 15));

        return $this->paginated($bookings->through(fn ($b) => new BookingResource($b)));
    }

    public function showBooking(string $ref): JsonResponse
    {
        $booking = Booking::with([
            'schedule.trip',
            'schedule.vehicle',
            'schedule.pickupPoints',
            'schedule.staff',
            'user',
            // จุดรับรายคน — แสดงและแก้ได้ทีละคนในหน้าจัดการการจอง
            'passengers.pickupPoint',
            'seats',
            'installmentPayments',
            'pickupPoint',
            // เอกสารแนบ — เฉพาะหน้ารายละเอียด ไม่ใส่ในหน้ารายการ เพราะแต่ละไฟล์
            // ต้อง mint ลิงก์ signed ใหม่ทุกครั้ง
            'documents',
        ])
            ->where('booking_ref', $ref)
            ->firstOrFail();

        return $this->success(new BookingResource($booking));
    }

    public function updateBookingStatus(Request $request, string $ref): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'in:pending,confirmed,cancelled,refunded'],
            'cancellation_reason' => ['nullable', 'string'],
        ]);

        $booking = Booking::with('seats')->where('booking_ref', $ref)->firstOrFail();

        if ($request->status === 'cancelled' || $request->status === 'refunded') {
            $booking = $this->bookingService->cancelBooking($booking, $request->cancellation_reason);
            if ($request->status === 'refunded') {
                $booking->update(['status' => 'refunded']);
            }
        } else {
            $oldStatus = $booking->status;
            $booking->update(['status' => $request->status]);

            // If changed from cancelled back to active, sync seats
            if (in_array($oldStatus, ['cancelled', 'refunded'])) {
                $booking->schedule?->syncBookedSeats();
            }
        }

        // Send status change email notification to customer
        $this->mailService->sendBookingStatusChangedEmail($booking->fresh(), $request->status);
        if ($request->status !== 'cancelled') {
            SmartNotification::send(
                $booking->user_id,
                $request->status === 'refunded' ? 'booking_refunded' : 'booking_status_changed',
                $request->status === 'refunded' ? 'ดำเนินการคืนเงินแล้ว' : 'อัปเดตสถานะการจอง',
                "เลขการจอง {$booking->booking_ref} เปลี่ยนสถานะเป็น {$request->status}",
                [
                    'booking_ref' => $booking->booking_ref,
                    'status' => $request->status,
                    'route' => 'booking',
                ],
            );
        }

        return $this->success(new BookingResource($booking->fresh()), 'อัปเดตสถานะสำเร็จ');
    }

    /**
     * GET /admin/bookings/{ref}/refund-preview
     * คำนวณยอดคืนเงินตาม policy โดยไม่บันทึกจริง
     */
    public function refundPreview(string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)
            ->with('schedule', 'passengers')
            ->firstOrFail();

        if (! in_array($booking->status, ['confirmed', 'cancelled'])) {
            return $this->error('สามารถดูตัวอย่างการคืนเงินได้เฉพาะการจองที่ยืนยันแล้วหรือยกเลิกแล้ว', 422);
        }

        $preview = $this->bookingService->calculateRefundAmount($booking);

        return $this->success([
            'booking_ref' => $booking->booking_ref,
            'payment_type' => $booking->payment_type ?? 'full',
            'paid_amount' => (float) $booking->paid_amount,
            'refund_percent' => $preview['refund_percent'],
            'refund_amount' => $preview['refund_amount'],
            'policy_note' => $preview['policy_note'],
        ]);
    }

    /**
     * POST /admin/bookings/{ref}/refund
     * Admin บันทึกการคืนเงิน — ยืนยันยอดและเหตุผล
     */
    public function processRefund(Request $request, string $ref): JsonResponse
    {
        $request->validate([
            'refund_amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'refund_slip' => ['nullable', 'image', 'max:5120'],
        ]);

        $booking = Booking::where('booking_ref', $ref)
            ->with('schedule', 'passengers')
            ->firstOrFail();

        if ($booking->status === 'refunded') {
            return $this->error('การจองนี้ถูกคืนเงินไปแล้ว', 422);
        }

        if (! in_array($booking->status, ['confirmed', 'cancelled'])) {
            return $this->error('ไม่สามารถคืนเงินการจองที่มีสถานะนี้ได้', 422);
        }

        $refundAmount = (float) $request->refund_amount;
        if ($refundAmount > (float) $booking->paid_amount) {
            return $this->error('ยอดคืนเงินต้องไม่เกิน ฿'.number_format($booking->paid_amount, 2), 422);
        }

        // Store the transfer slip on the private disk (same as payment slips).
        $slipPath = null;
        if ($request->hasFile('refund_slip')) {
            $slipPath = $request->file('refund_slip')->store('refund-slips/'.date('Y/m'), MediaDisk::slipDisk());
        }

        $booking = $this->bookingService->processRefund($booking, $refundAmount, $request->note, $slipPath);

        $this->mailService->sendBookingStatusChangedEmail($booking, 'refunded');

        SmartNotification::send(
            $booking->user_id,
            'booking_refunded',
            'ดำเนินการคืนเงินแล้ว',
            "เลขการจอง {$booking->booking_ref} ได้รับการคืนเงิน ฿".number_format($refundAmount, 0).' แล้ว',
            ['booking_ref' => $booking->booking_ref, 'refund_amount' => $refundAmount, 'route' => 'booking'],
        );

        return $this->success([
            'booking_ref' => $booking->booking_ref,
            'status' => $booking->status,
            'refund_amount' => (float) $booking->refund_amount,
            'refunded_at' => $booking->refunded_at?->toISOString(),
            'refund_slip_url' => MediaDisk::slipUrl($booking->refund_slip_path),
        ], 'คืนเงิน ฿'.number_format($refundAmount, 0).' สำเร็จ');
    }

    /**
     * POST /admin/bookings/{ref}/transfer
     * ย้ายการจองไปยังบัญชีผู้ใช้อื่น
     */
    public function transferBooking(Request $request, string $ref): JsonResponse
    {
        $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
        ]);

        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        if (in_array($booking->status, ['cancelled', 'refunded'])) {
            return $this->error('ไม่สามารถย้ายการจองที่ยกเลิกแล้วหรือคืนเงินแล้วได้', 422);
        }

        $targetUser = null;
        if ($request->filled('user_id')) {
            $targetUser = User::find($request->user_id);
        } elseif ($request->filled('email')) {
            $targetUser = User::where('email', $request->email)->first();
        } elseif ($request->filled('phone')) {
            $targetUser = User::where('phone', $request->phone)->first();
        }

        if (! $targetUser) {
            return $this->error('ไม่พบบัญชีผู้ใช้ที่ต้องการ', 404);
        }

        if ($targetUser->id === $booking->user_id) {
            return $this->error('การจองนี้อยู่ในบัญชีนี้อยู่แล้ว', 422);
        }

        $previousUserId = $booking->user_id;
        $booking->update(['user_id' => $targetUser->id]);

        SmartNotification::send(
            $targetUser->id,
            'booking_transferred',
            'ได้รับการจองใหม่',
            "เลขการจอง {$booking->booking_ref} ถูกโอนมาที่บัญชีของคุณแล้ว",
            ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
        );

        \Log::info('Booking transferred', [
            'booking_ref' => $booking->booking_ref,
            'from_user_id' => $previousUserId,
            'to_user_id' => $targetUser->id,
            'transferred_by' => $request->user()->id,
        ]);

        return $this->success([
            'booking_ref' => $booking->booking_ref,
            'new_user' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'phone' => $targetUser->phone,
            ],
        ], "ย้ายการจองไปยัง {$targetUser->name} สำเร็จ");
    }

    public function updateBooking(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,confirmed,cancelled,refunded'],
            'schedule_id' => ['nullable', 'exists:trip_schedules,id'],
            // ย้ายคันภายในรอบเดียวกัน (บัส → ตู้) — ว่าง = ไม่ระบุคัน (รอบที่มีรถคันเดียว)
            'vehicle_option_id' => ['nullable', 'integer', 'exists:schedule_vehicle_options,id'],
            'pickup_region' => ['nullable', 'string', 'max:100'],
            'pickup_point_id' => ['nullable', 'exists:schedule_pickup_points,id'],
            'custom_pickup_label' => ['nullable', 'string', 'max:255'],
            'custom_pickup_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:custom_pickup_lng'],
            'custom_pickup_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:custom_pickup_lat'],
            'custom_pickup_note' => ['nullable', 'string', 'max:1000'],
            'custom_pickup_price' => ['nullable', 'numeric', 'min:0'],
            'clear_custom_pickup' => ['nullable', 'boolean'],
            'is_join_trip' => ['nullable', 'boolean'],
            'is_group' => ['nullable', 'boolean'],
            'group_name' => ['nullable', 'string', 'max:255'],
            'group_notes' => ['nullable', 'string'],
            'qr_code' => ['nullable', 'string', 'max:255'],
            'checked_in' => ['nullable', 'boolean'],
            'checked_in_at' => ['nullable', 'date'],
            'cancellation_reason' => ['nullable', 'string'],
            'cancelled_at' => ['nullable', 'date'],
            'user.name' => ['nullable', 'string', 'max:255'],
            'user.email' => ['nullable', 'email', 'max:255'],
            'user.phone' => ['nullable', 'string', 'max:30'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'payment_type' => ['nullable', 'in:full,deposit,installment'],
            'installment_count' => ['nullable', 'integer', 'min:1', 'max:12'],
            'installment_interval_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'balance_amount' => ['nullable', 'numeric', 'min:0'],
            'balance_due_at' => ['nullable', 'date'],
            'balance_paid_at' => ['nullable', 'date'],
            'balance_payment_ref' => ['nullable', 'string', 'max:255'],
            'balance_transfer_datetime' => ['nullable', 'date'],
            'delete_balance_slip' => ['nullable', 'boolean'],
            'balance_slip_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'payment_ref' => ['nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date'],
            'transfer_datetime' => ['nullable', 'date'],
            'delete_slip' => ['nullable', 'boolean'],
            'slip_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'seat_ids' => ['nullable', 'array'],
            'seat_ids.*' => ['nullable', 'string', 'max:30'],
            'passengers' => ['nullable', 'array'],
            'passengers.*.id' => ['nullable', 'integer', 'exists:booking_passengers,id'],
            'passengers.*.title' => ['nullable', 'string', 'max:50'],
            'passengers.*.name' => ['required_with:passengers', 'string', 'max:255'],
            'passengers.*.nickname' => ['nullable', 'string', 'max:100'],
            'passengers.*.id_card' => ['nullable', 'string', 'max:50'],
            'passengers.*.phone' => ['nullable', 'string', 'max:30'],
            'passengers.*.email' => ['nullable', 'email', 'max:255'],
            'passengers.*.blood_group' => ['nullable', 'string', 'max:20'],
            'passengers.*.allergies' => ['nullable', 'string'],
            'passengers.*.health_notes' => ['nullable', 'string'],
            'passengers.*.emergency_contact' => ['nullable', 'string', 'max:255'],
            'passengers.*.emergency_phone' => ['nullable', 'string', 'max:30'],
            'passengers.*.dive_cert_level' => ['nullable', 'string', 'max:255'],
            'passengers.*.cert_number' => ['nullable', 'string', 'max:255'],
            'passengers.*.weight' => ['nullable', 'numeric', 'min:0'],
            'passengers.*.halal_food' => ['nullable', 'boolean'],
            // จุดรับรายคน — กลุ่มเดียวกันขึ้นคนละจุดเป็นเรื่องปกติ
            'passengers.*.pickup_point_id' => ['nullable', 'integer'],
            // จุดรับรายคน — คนในกลุ่มเดียวกันขึ้นคนละจุดได้ (ว่าง = ตามจุดของการจอง)
            'passengers.*.pickup_point_id' => ['nullable', 'exists:schedule_pickup_points,id'],
            'installments' => ['nullable', 'array'],
            'installments.*.id' => ['nullable', 'integer', 'exists:installment_payments,id'],
            'installments.*.installment_no' => ['required_with:installments', 'integer', 'min:1', 'max:12'],
            'installments.*.amount' => ['required_with:installments', 'numeric', 'min:0'],
            'installments.*.due_date' => ['nullable', 'date'],
            'installments.*.status' => ['nullable', 'in:pending,paid,failed,cancelled'],
            'installments.*.payment_method' => ['nullable', 'string', 'max:100'],
            'installments.*.payment_ref' => ['nullable', 'string', 'max:255'],
            'installments.*.paid_at' => ['nullable', 'date'],
            'installments.*.transfer_datetime' => ['nullable', 'date'],
            'installments.*.delete_slip' => ['nullable', 'boolean'],
            'installments.*.slip_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            // อุปกรณ์เช่าเพิ่มเติม — ส่ง sync_rentals=1 เพื่อเขียนทับทั้งชุด (ชุดว่าง = ลบทั้งหมด)
            'sync_rentals' => ['nullable', 'boolean'],
            'selected_rentals' => ['nullable', 'array'],
            'selected_rentals.*.name' => ['required_with:selected_rentals', 'string', 'max:255'],
            'selected_rentals.*.unit_price' => ['required_with:selected_rentals', 'numeric', 'min:0'],
            'selected_rentals.*.quantity' => ['required_with:selected_rentals', 'integer', 'min:1', 'max:50'],
            'selected_rentals.*.image_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $booking = Booking::with(['user', 'passengers', 'seats', 'installmentPayments', 'schedule'])
            ->where('booking_ref', $ref)
            ->firstOrFail();

        // ที่นั่งของรอบปลายทางก่อนแก้ไข — ใช้เทียบว่าการแก้ครั้งนี้ทำให้ที่นั่งเพิ่มจนต้องแจ้งเตือนไหม
        $destinationBefore = TripSchedule::find($data['schedule_id'] ?? $booking->schedule_id);
        $destinationBefore?->syncBookedSeats();
        $bookedBeforeUpdate = $destinationBefore ? (int) $destinationBefore->booked_seats : null;

        try {
            DB::transaction(function () use ($request, $data, $booking) {
                $oldSchedule = $booking->schedule;

                // จุดรับรายคนมาก่อนจุดระดับการจองเสมอ (สตาฟ/คนขับจัดกลุ่มจากรายคน)
                // จำไว้ว่าใคร "ยืนจุดเดียวกับหัวการจอง" ก่อนแก้ เพื่อย้ายตามเมื่อแอดมิน
                // เปลี่ยนจุดรับ — คนที่เลือกจุดของตัวเองไว้ต่างหากจะไม่ถูกทับ
                // คันที่ใบนี้นั่งอยู่ก่อนแก้ — ที่นั่งผูกกับคัน (A1 ของบัสกับ A1 ของตู้
                // เป็นคนละที่) แถวที่นั่งจึงต้องย้ายตามเมื่อแอดมินเปลี่ยนคันให้
                $originalOptionId = (int) ($booking->vehicle_option_id ?? 0);
                $newOption = null;
                $optionChosen = array_key_exists('vehicle_option_id', $data);

                $originalPickupPointId = $booking->pickup_point_id ? (int) $booking->pickup_point_id : null;
                $pickupFollowerIds = $booking->passengers
                    ->filter(function ($passenger) use ($originalPickupPointId) {
                        $own = $passenger->pickup_point_id ? (int) $passenger->pickup_point_id : null;

                        return $own === null || $own === $originalPickupPointId;
                    })
                    ->pluck('id')
                    ->all();

                if ($booking->user && isset($data['user'])) {
                    $booking->user->update(array_filter([
                        'name' => $data['user']['name'] ?? null,
                        'email' => $data['user']['email'] ?? null,
                        'phone' => $data['user']['phone'] ?? null,
                    ], fn ($value) => $value !== null));
                }

                $bookingUpdates = [];
                foreach ([
                    'status', 'schedule_id', 'pickup_region', 'pickup_point_id', 'group_name', 'group_notes',
                    'qr_code', 'cancellation_reason', 'total_amount', 'paid_amount', 'payment_method',
                    'payment_type', 'installment_count', 'installment_interval_days', 'payment_ref',
                    'paid_at', 'transfer_datetime', 'cancelled_at', 'checked_in_at',
                    'deposit_amount', 'balance_amount', 'balance_due_at', 'balance_payment_ref',
                    'balance_paid_at', 'balance_transfer_datetime',
                ] as $field) {
                    if (array_key_exists($field, $data)) {
                        $bookingUpdates[$field] = $data[$field];
                    }
                }

                if (array_key_exists('is_join_trip', $data)) {
                    $bookingUpdates['is_join_trip'] = (bool) $data['is_join_trip'];
                }
                if (array_key_exists('is_group', $data)) {
                    $bookingUpdates['is_group'] = (bool) $data['is_group'];
                }

                // ย้ายคันภายในรอบ (บัส → ตู้) — ทางเดียวที่จะเอาใบจองออกจากตัวเลือก
                // ที่แอดมินอยากเลิกใช้ โดยไม่ต้องยกเลิกใบจองหรือย้ายรอบ
                if ($optionChosen) {
                    $targetScheduleId = (int) ($bookingUpdates['schedule_id'] ?? $booking->schedule_id);
                    $joinTrip = (bool) ($bookingUpdates['is_join_trip'] ?? $booking->is_join_trip);
                    $paxCount = array_key_exists('passengers', $data) && $data['passengers'] !== null
                        ? count($data['passengers'])
                        : $booking->passengers->count();

                    // จอยทริปไม่กินที่นั่งบนรถ จึงไม่มีคันให้เลือก (เหมือนตอนลูกค้าจอง)
                    if (filled($data['vehicle_option_id']) && ! $joinTrip) {
                        $newOption = ScheduleVehicleOption::where('schedule_id', $targetScheduleId)
                            ->find((int) $data['vehicle_option_id']);

                        if (! $newOption) {
                            throw new \RuntimeException('ประเภทรถที่เลือกไม่ได้อยู่ในรอบเดินทางนี้');
                        }
                    }

                    // โควตาของคันปลายทางต้องรับไหว — นับใหม่จากใบจองจริงก่อนตัดสิน
                    if ($newOption && (int) $newOption->id !== $originalOptionId && $newOption->seats !== null) {
                        TripSchedule::with('vehicleOptions')->find($targetScheduleId)?->syncVehicleOptionSeats();
                        $newOption->refresh();

                        if (! $newOption->canFit($paxCount)) {
                            throw new \RuntimeException(
                                $newOption->label.' เหลือ '.(int) $newOption->available_seats.' ที่ ไม่พอสำหรับ '.$paxCount.' ท่าน'
                            );
                        }
                    }

                    // สำเนาชื่อ/ส่วนต่างไว้บนใบจองเหมือนตอนจอง — ใบนี้ต้องอธิบายยอดของ
                    // ตัวเองได้ตลอดไปแม้ตัวเลือกจะถูกแก้ราคาหรือปิดทีหลัง
                    $bookingUpdates['vehicle_option_id'] = $newOption?->id;
                    $bookingUpdates['vehicle_option_label'] = $newOption?->label;
                    $bookingUpdates['vehicle_option_adjustment'] = $newOption ? (float) $newOption->price_adjustment : null;

                    // ส่วนต่างต่อคนเปลี่ยนตามคัน — ผู้เรียกที่ไม่ได้ส่งยอดมาเองให้ปรับให้
                    // (หน้าแอดมินคิดให้เห็นก่อนกดบันทึกและส่ง total_amount มาเสมอ)
                    $adjustmentDelta = ((float) ($newOption?->price_adjustment ?? 0)
                        - (float) ($booking->vehicle_option_adjustment ?? 0)) * $paxCount;

                    if ($adjustmentDelta != 0.0) {
                        if (! array_key_exists('total_amount', $data)) {
                            $bookingUpdates['total_amount'] = max(0, (float) $booking->total_amount + $adjustmentDelta);
                        }
                        if (! array_key_exists('balance_amount', $data) && $booking->balance_amount !== null) {
                            $bookingUpdates['balance_amount'] = max(0, (float) $booking->balance_amount + $adjustmentDelta);
                        }
                    }
                }

                // อุปกรณ์เช่า — แอดมินเพิ่ม/แก้จำนวนให้ลูกค้าที่ขอทีหลังได้ (เต็นท์ ถุงนอน หมอน)
                // เก็บเป็น snapshot เหมือนตอนจอง เพราะ catalog ของทริปแก้ราคาทีหลังได้
                $rentalNames = null;
                if ($request->boolean('sync_rentals')) {
                    $rentalSnapshots = [];
                    $rentalsTotal = 0.0;

                    foreach ($data['selected_rentals'] ?? [] as $rental) {
                        $quantity = (int) $rental['quantity'];
                        $unitPrice = (float) $rental['unit_price'];
                        $linePrice = $unitPrice * $quantity;
                        $rentalsTotal += $linePrice;

                        $rentalSnapshots[] = [
                            'name' => trim($rental['name']),
                            'unit_price' => $unitPrice,
                            'quantity' => $quantity,
                            'total_price' => $linePrice,
                            'image_url' => (string) ($rental['image_url'] ?? ''),
                        ];
                    }

                    $bookingUpdates['selected_rentals'] = $rentalSnapshots;
                    $bookingUpdates['rentals_total'] = $rentalsTotal;
                    $rentalNames = array_column($rentalSnapshots, 'name');
                }

                // จุดรับที่ปักหมุดเอง — แอดมินปักหมุด/แก้ไข/ลบได้จากหน้าแก้ไขการจอง
                $customPickupSet = false;
                if ($request->boolean('clear_custom_pickup')) {
                    $bookingUpdates['custom_pickup_label'] = null;
                    $bookingUpdates['custom_pickup_lat'] = null;
                    $bookingUpdates['custom_pickup_lng'] = null;
                    $bookingUpdates['custom_pickup_note'] = null;
                    $bookingUpdates['custom_pickup_status'] = null;
                    $bookingUpdates['custom_pickup_price'] = null;
                    $bookingUpdates['custom_pickup_reject_reason'] = null;
                    $bookingUpdates['custom_pickup_resolved_at'] = null;
                } elseif (($data['custom_pickup_lat'] ?? null) !== null && ($data['custom_pickup_lng'] ?? null) !== null) {
                    $bookingUpdates['custom_pickup_label'] = $data['custom_pickup_label'] ?? $booking->custom_pickup_label;
                    $bookingUpdates['custom_pickup_lat'] = $data['custom_pickup_lat'];
                    $bookingUpdates['custom_pickup_lng'] = $data['custom_pickup_lng'];
                    $bookingUpdates['custom_pickup_note'] = $data['custom_pickup_note'] ?? null;
                    if (array_key_exists('custom_pickup_price', $data) && $data['custom_pickup_price'] !== null) {
                        $bookingUpdates['custom_pickup_price'] = $data['custom_pickup_price'];
                    }
                    // จุดที่แอดมินปักเองถือว่าอนุมัติแล้ว (ตั้งราคาเริ่มต้น 0 หากยังไม่เคยมี)
                    if ($booking->custom_pickup_status === null) {
                        $bookingUpdates['custom_pickup_status'] = Booking::CUSTOM_PICKUP_APPROVED;
                        $bookingUpdates['custom_pickup_price'] = $bookingUpdates['custom_pickup_price'] ?? ($booking->custom_pickup_price ?? 0);
                        $bookingUpdates['custom_pickup_resolved_at'] = now();
                    }
                    // จุดปักหมุดกับจุดรับที่กำหนดไว้ใช้ร่วมกันไม่ได้ — ล้างจุดรับตายตัวออก
                    // เพื่อให้หน้าสตาฟ (buildPickupGroups) แสดงหมุดของลูกค้าได้ (เงื่อนไข ! pickup_point_id)
                    $bookingUpdates['pickup_point_id'] = null;
                    $bookingUpdates['pickup_region'] = null;
                    $customPickupSet = true;
                }
                if (array_key_exists('checked_in', $data)) {
                    $bookingUpdates['checked_in'] = (bool) $data['checked_in'];
                    if (! $bookingUpdates['checked_in']) {
                        $bookingUpdates['checked_in_at'] = null;
                    } elseif (empty($bookingUpdates['checked_in_at']) && ! $booking->checked_in_at) {
                        $bookingUpdates['checked_in_at'] = now();
                    }
                }

                if (($data['delete_slip'] ?? false) && $booking->slip_path) {
                    Storage::disk(MediaDisk::slipDisk())->delete($booking->slip_path);
                    $bookingUpdates['slip_path'] = null;
                }
                if ($request->hasFile('slip_image')) {
                    if ($booking->slip_path) {
                        Storage::disk(MediaDisk::slipDisk())->delete($booking->slip_path);
                    }
                    $bookingUpdates['slip_path'] = $request->file('slip_image')->store('slips/'.date('Y/m'), MediaDisk::slipDisk());
                }

                if (($data['delete_balance_slip'] ?? false) && $booking->balance_slip_path) {
                    Storage::disk(MediaDisk::slipDisk())->delete($booking->balance_slip_path);
                    $bookingUpdates['balance_slip_path'] = null;
                }
                if ($request->hasFile('balance_slip_image')) {
                    if ($booking->balance_slip_path) {
                        Storage::disk(MediaDisk::slipDisk())->delete($booking->balance_slip_path);
                    }
                    $bookingUpdates['balance_slip_path'] = $request->file('balance_slip_image')->store('slips/'.date('Y/m'), MediaDisk::slipDisk());
                }

                // ย้ายรอบเดินทาง (รวมข้ามทริป) — จุดรับผูกกับรอบ ถ้าไม่ย้ายตาม การจองจะยัง
                // ชี้จุดรับของรอบเดิม ทำให้เวลารับที่สตาฟเห็นตอนเช็คอินเป็นเวลาของทริปเดิม
                $newScheduleId = (int) ($bookingUpdates['schedule_id'] ?? $booking->schedule_id);
                if ($oldSchedule && $newScheduleId !== (int) $oldSchedule->id) {
                    $newSchedule = TripSchedule::with('pickupPoints')->find($newScheduleId);

                    if ($newSchedule) {
                        $pickupMap = $this->pickupPointMap($oldSchedule, $newSchedule);
                        $this->remapPassengerPickupPoints($booking, $pickupMap);

                        // แอดมินระบุจุดรับใหม่มาเอง หรือเพิ่งปักหมุด → เคารพค่าที่ส่งมา
                        if (! $customPickupSet && ! array_key_exists('pickup_point_id', $data)) {
                            $bookingUpdates = array_merge(
                                $bookingUpdates,
                                $this->resolveMovedPickup($booking, $oldSchedule, $newSchedule, $pickupMap),
                            );
                        }
                    }
                }

                if ($bookingUpdates) {
                    $booking->update($bookingUpdates);
                }

                // ใบแจกอุปกรณ์อ้างด้วยชื่อ — รายการที่ถูกลบ/เปลี่ยนชื่อจึงต้องเก็บกวาด
                // ไม่งั้นหน้าสตาฟจะค้างสถานะแจก/คืนของอุปกรณ์ที่ไม่มีอยู่แล้ว
                if ($rentalNames !== null) {
                    $booking->rentalHandouts()
                        ->when($rentalNames, fn ($query) => $query->whereNotIn('item_name', $rentalNames))
                        ->delete();
                }

                if (array_key_exists('passengers', $data)) {
                    $keptIds = [];
                    foreach ($data['passengers'] ?? [] as $passengerData) {
                        $passengerId = $passengerData['id'] ?? null;
                        $payload = [
                            'title' => $passengerData['title'] ?? '',
                            'name' => $passengerData['name'],
                            'nickname' => $passengerData['nickname'] ?? null,
                            'id_card' => $passengerData['id_card'] ?? null,
                            'phone' => $passengerData['phone'] ?? null,
                            'email' => $passengerData['email'] ?? null,
                            'blood_group' => $passengerData['blood_group'] ?? null,
                            'allergies' => $passengerData['allergies'] ?? null,
                            'health_notes' => $passengerData['health_notes'] ?? null,
                            'emergency_contact' => $passengerData['emergency_contact'] ?? null,
                            'emergency_phone' => $passengerData['emergency_phone'] ?? null,
                            'dive_cert_level' => $passengerData['dive_cert_level'] ?? null,
                            'cert_number' => $passengerData['cert_number'] ?? null,
                            'weight' => $passengerData['weight'] ?? null,
                            'halal_food' => array_key_exists('halal_food', $passengerData) ? (bool) $passengerData['halal_food'] : null,
                        ];

                        // แอดมินระบุจุดรับให้คนนี้เอง — ค่าว่างแปลว่า "ตามจุดของการจอง"
                        if (array_key_exists('pickup_point_id', $passengerData)) {
                            $payload['pickup_point_id'] = $passengerData['pickup_point_id'] ?: null;
                        }

                        $passenger = $passengerId
                            ? $booking->passengers()->whereKey($passengerId)->first()
                            : null;

                        if ($passenger) {
                            $passenger->update($payload);
                        } else {
                            $passenger = $booking->passengers()->create($payload);
                        }
                        $keptIds[] = $passenger->id;
                    }

                    $booking->passengers()->whereNotIn('id', $keptIds ?: [0])->delete();
                }

                // ปักหมุดเองเป็นระดับการจอง — ล้างจุดรับรายคนที่ค้างจากจุดตายตัวเดิม
                // ไม่งั้นหน้าสตาฟจะจัดกลุ่มผู้โดยสารเข้าจุดเก่าแทนหมุด
                // แอดมินส่งจุดรับรายคนมาเอง = ค่าที่ตั้งไว้ต่อคนเป็นใหญ่ อย่าให้การ
                // ย้ายตามจุดของการจองด้านล่างมาทับสิ่งที่เพิ่งเลือกให้แต่ละคน
                $explicitPassengerPickup = collect($data['passengers'] ?? [])
                    ->contains(fn ($passengerData) => array_key_exists('pickup_point_id', $passengerData));

                if ($customPickupSet) {
                    $booking->passengers()->update(['pickup_point_id' => null]);
                } elseif (! $explicitPassengerPickup && array_key_exists('pickup_point_id', $data)) {
                    // เปลี่ยนจุดรับระดับการจอง — ผู้โดยสารที่ยังยืนจุดเดิมต้องย้ายตามด้วย
                    // ไม่งั้นหน้าสตาฟ/คนขับที่อ่านจุดรายคนก่อนจะยังเห็นจุดเก่า
                    $newPickupPointId = $data['pickup_point_id'] ? (int) $data['pickup_point_id'] : null;
                    if ($newPickupPointId !== $originalPickupPointId && $pickupFollowerIds) {
                        $booking->passengers()
                            ->whereKey($pickupFollowerIds)
                            ->update(['pickup_point_id' => $newPickupPointId]);
                    }
                }

                if (array_key_exists('seat_ids', $data)) {
                    // ล็อกรอบเดินทางก่อนแก้ที่นั่ง — ลบของเดิมแล้วค่อยตรวจ จึงไม่ติดที่นั่งตัวเอง
                    TripSchedule::lockForUpdate()->find($booking->schedule_id);
                    $booking->seats()->delete();
                    if (! ($booking->fresh()->is_join_trip)) {
                        // ที่นั่งส่งมาเรียงตามผู้โดยสาร ช่องที่เว้นว่างคือคนที่ยังไม่ระบุที่นั่ง
                        // จึงต้องคงลำดับเดิมไว้ ไม่งั้นชื่อบนที่นั่งจะเลื่อนไปคนถัดไป
                        $seatInputs = $data['seat_ids'] ?? [];
                        $newSeatIds = array_values(array_filter($seatInputs, fn ($seatId) => filled($seatId)));

                        // กันที่นั่งซ้ำภายในคำขอเดียว ก่อนตรวจกับแถวอื่น
                        $duplicateSeatIds = collect($newSeatIds)->duplicates()->unique()->values();
                        if ($duplicateSeatIds->isNotEmpty()) {
                            throw new \RuntimeException('เลือกที่นั่งซ้ำกัน: '.$duplicateSeatIds->join(', ').' กรุณาเลือกที่นั่งใหม่');
                        }

                        // ที่นั่งเหล่านี้ต้องไม่มีแถวค้างของ booking อื่นเลย (unique constraint ไม่สนสถานะ)
                        // ที่นั่งผูกกับคัน — A1 ของบัสกับ A1 ของตู้ไม่ชนกัน
                        $seatOptionId = (int) ($booking->vehicle_option_id ?? 0);

                        $occupied = BookingSeat::where('schedule_id', $booking->schedule_id)
                            ->where('vehicle_option_id', $seatOptionId)
                            ->whereIn('seat_id', $newSeatIds)
                            ->pluck('seat_id')
                            ->unique()
                            ->values();

                        if ($occupied->isNotEmpty()) {
                            throw new \RuntimeException('ที่นั่ง '.$occupied->join(', ').' ถูกจองแล้ว');
                        }

                        $seatPassengers = $booking->passengers()->orderBy('id')->get();

                        foreach ($seatInputs as $index => $seatId) {
                            if (! filled($seatId)) {
                                continue;
                            }

                            $booking->seats()->create([
                                'schedule_id' => $booking->schedule_id,
                                'vehicle_option_id' => $seatOptionId,
                                'seat_id' => $seatId,
                                'passenger_name' => $seatPassengers->get($index)?->name,
                            ]);
                        }
                    }
                }

                // ย้ายรอบ/ย้ายคันโดยไม่ได้เลือกที่นั่งใหม่ — แถวที่นั่งยังค้างอยู่ใต้รอบและคันเดิม
                // ต้องย้ายตามไปด้วย ไม่งั้นที่นั่งค้างกินโควตาของเดิม รอบใหม่นับที่นั่งไม่ตรง
                // และที่นั่งผูกกับคัน (A1 ของบัสกับ A1 ของตู้เป็นคนละที่) ลูกค้าจะถือ
                // ที่นั่งของคันที่ไม่ได้นั่งอีกแล้ว
                $scheduleChanged = $oldSchedule && $oldSchedule->id !== $booking->schedule_id;
                $optionChanged = $optionChosen && (int) ($booking->vehicle_option_id ?? 0) !== $originalOptionId;

                if (! array_key_exists('seat_ids', $data) && ($scheduleChanged || $optionChanged)) {
                    $movingSeats = $booking->seats()
                        ->where('schedule_id', $oldSchedule?->id ?? $booking->schedule_id)
                        ->where('vehicle_option_id', $originalOptionId)
                        ->get();

                    if ($movingSeats->isNotEmpty()) {
                        $newSchedule = TripSchedule::lockForUpdate()->find($booking->schedule_id);

                        // แอดมินระบุคันมาเองในคำขอนี้ = คำสั่งตรง ไม่ต้องเดา; ย้ายรอบเฉย ๆ
                        // ให้หาคันชื่อเดียวกันในรอบปลายทาง ไม่เจอก็ไปอยู่กองรถคันเดียว
                        // ของรอบนั้น (0) พร้อมกับตัวใบจองเอง
                        if ($optionChosen) {
                            $movedOption = $newOption;
                        } else {
                            $movedOption = $newSchedule?->vehicleOptionByLabel($booking->vehicle_option_label);
                            $booking->update(['vehicle_option_id' => $movedOption?->id]);
                        }
                        $movedOptionId = (int) ($movedOption?->id ?? 0);

                        // คันที่ทีมงานจัดที่นั่งหน้างานไม่มีผังให้ลูกค้ายึด — ปล่อยที่นั่งคืน
                        // เหมือนตอนจอง แทนที่จะยกเลขที่นั่งของคันเดิมข้ามมา
                        if ($movedOption && ! $movedOption->seat_selection) {
                            $booking->seats()->whereKey($movingSeats->pluck('id'))->delete();
                        } else {
                            $taken = BookingSeat::where('schedule_id', $booking->schedule_id)
                                ->where('vehicle_option_id', $movedOptionId)
                                ->whereIn('seat_id', $movingSeats->pluck('seat_id'))
                                ->whereNot('booking_id', $booking->id)
                                ->pluck('seat_id')
                                ->unique()
                                ->values();

                            if ($taken->isNotEmpty()) {
                                throw new \RuntimeException('ที่นั่ง '.$taken->join(', ').' ปลายทางถูกจองแล้ว กรุณาเลือกที่นั่งใหม่ให้การจองนี้');
                            }

                            $booking->seats()->whereKey($movingSeats->pluck('id'))
                                ->update([
                                    'schedule_id' => $booking->schedule_id,
                                    'vehicle_option_id' => $movedOptionId,
                                ]);
                        }
                    }
                }

                // ที่นั่งบนผังผูกกับผู้โดยสารแบบ 1:1 — ลบผู้โดยสารแล้วต้องปล่อยที่นั่งคืนด้วย
                // ไม่งั้นแถวที่นั่งค้างไว้ทำให้คนอื่นจองเบอร์นั้นซ้ำไม่ได้ (unique schedule_id+seat_id)
                // แม้ booked_seats จะลดลงแล้วก็ตาม
                // ถ้าคำขอนี้ส่งที่นั่งมาเอง แถวที่นั่งเพิ่งถูกสร้างพร้อมชื่อที่ถูกต้อง
                // ตามช่องของแต่ละคนแล้ว การจับคู่ใหม่ตามลำดับจะทำให้ชื่อเพี้ยน
                // เมื่อมีคนเว้นที่นั่งว่างไว้ตรงกลาง
                $this->syncSeatRowsToPassengers(
                    $booking->fresh(['passengers', 'seats']),
                    relabel: ! array_key_exists('seat_ids', $data),
                );

                $effectiveType = $data['payment_type'] ?? $booking->payment_type;
                if ($effectiveType !== 'installment') {
                    // เปลี่ยนเป็นชำระเต็ม/มัดจำ — เคลียร์งวดผ่อนเดิมทิ้งพร้อมสลิป
                    if (array_key_exists('payment_type', $data)) {
                        foreach ($booking->installmentPayments as $payment) {
                            if ($payment->slip_path) {
                                Storage::disk(MediaDisk::slipDisk())->delete($payment->slip_path);
                            }
                        }
                        $booking->installmentPayments()->delete();
                        $booking->update([
                            'installment_count' => null,
                            'installment_interval_days' => null,
                        ]);
                    }
                } elseif (array_key_exists('installments', $data)) {
                    $keptInstallmentIds = [];
                    foreach ($data['installments'] ?? [] as $index => $installmentData) {
                        $installment = isset($installmentData['id'])
                            ? $booking->installmentPayments()->whereKey($installmentData['id'])->first()
                            : null;

                        $paymentPayload = [
                            'installment_no' => $installmentData['installment_no'],
                            'amount' => $installmentData['amount'],
                            'due_date' => $installmentData['due_date'] ?? null,
                            'status' => $installmentData['status'] ?? 'pending',
                            'payment_method' => $installmentData['payment_method'] ?? null,
                            'payment_ref' => $installmentData['payment_ref'] ?? null,
                            'paid_at' => $installmentData['paid_at'] ?? null,
                            'transfer_datetime' => $installmentData['transfer_datetime'] ?? null,
                        ];

                        if ($installment) {
                            $installment->update($paymentPayload);
                        } else {
                            $installment = $booking->installmentPayments()->create($paymentPayload);
                        }

                        if (($installmentData['delete_slip'] ?? false) && $installment->slip_path) {
                            Storage::disk(MediaDisk::slipDisk())->delete($installment->slip_path);
                            $installment->update(['slip_path' => null]);
                        }

                        $file = $request->file("installments.$index.slip_image");
                        if ($file) {
                            if ($installment->slip_path) {
                                Storage::disk(MediaDisk::slipDisk())->delete($installment->slip_path);
                            }
                            $installment->update([
                                'slip_path' => $file->store('slips/'.date('Y/m'), MediaDisk::slipDisk()),
                            ]);
                        }

                        $keptInstallmentIds[] = $installment->id;
                    }

                    $removedPayments = $booking->installmentPayments()->whereNotIn('id', $keptInstallmentIds ?: [0])->get();
                    foreach ($removedPayments as $payment) {
                        if ($payment->slip_path) {
                            Storage::disk(MediaDisk::slipDisk())->delete($payment->slip_path);
                        }
                        $payment->delete();
                    }
                }

                // โหลดตัวเลือกยานพาหนะมาด้วย เพื่อให้ตัวนับรายคัน (booked_seats ของ
                // บัส/ตู้) นับใหม่ตามไปพร้อมกัน — syncBookedSeats() แตะตัวเลือกเฉพาะ
                // เมื่อความสัมพันธ์ถูกโหลดมาแล้วเท่านั้น
                TripSchedule::with('vehicleOptions')->find($booking->fresh()->schedule_id)?->syncBookedSeats();
                if ($oldSchedule && $oldSchedule->id !== $booking->schedule_id) {
                    $oldSchedule->load('vehicleOptions');
                    $oldSchedule->syncBookedSeats();
                }
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        // ย้ายการจองเข้ารอบใหม่ก็ทำให้ที่นั่งรอบนั้นเพิ่ม — แจ้งเตือนเหมือนตอนลูกค้าจอง
        $destination = TripSchedule::find($booking->fresh()->schedule_id);
        if ($destination) {
            $this->seatNotifier->seatsIncreased(
                $destination->id,
                $bookedBeforeUpdate,
                (int) $destination->booked_seats,
                $booking->booking_ref,
            );
        }

        $booking = Booking::with([
            'schedule.trip',
            'schedule.vehicle',
            'schedule.pickupPoints',
            'schedule.staff',
            'user',
            'passengers',
            'seats',
            'installmentPayments',
            'pickupPoint',
        ])->where('booking_ref', $ref)->firstOrFail();

        return $this->success(new BookingResource($booking), 'อัปเดตข้อมูลการจองสำเร็จ');
    }

    /**
     * บังคับให้แถว booking_seats มีจำนวนไม่เกินผู้โดยสาร และชื่อบนที่นั่งตรงกับผู้โดยสาร
     * ที่นั่งส่วนเกิน (จากการลบผู้โดยสาร) จะถูกปล่อยคืนโดยตัดจากท้ายรายการ
     *
     * ต้องรับ booking ที่โหลด passengers + seats มาแล้ว
     */
    /**
     * @param  bool  $relabel  จับคู่ชื่อบนแถวที่นั่งกับผู้โดยสารตามลำดับใหม่ ใช้ได้เฉพาะ
     *                         ตอนที่ที่นั่งเรียงชิดกันตั้งแต่คนแรก (ไม่มีช่องเว้นกลาง)
     */
    private function syncSeatRowsToPassengers(Booking $booking, bool $relabel = true): void
    {
        if ($booking->is_join_trip) {
            return;
        }

        $passengers = $booking->passengers->sortBy('id')->values();
        $seats = $booking->seats->sortBy('id')->values();

        // ยังไม่มีผู้โดยสารเลย = ข้อมูลยังกรอกไม่ครบ ไม่ใช่การลบ จึงไม่แตะที่นั่ง
        if ($passengers->isEmpty() || $seats->isEmpty()) {
            return;
        }

        if ($seats->count() > $passengers->count()) {
            $surplus = $seats->slice($passengers->count());
            BookingSeat::whereKey($surplus->pluck('id'))->delete();
            $seats = $seats->take($passengers->count());
        }

        if (! $relabel) {
            return;
        }

        foreach ($seats as $index => $seat) {
            $name = $passengers[$index]->name;
            if ($seat->passenger_name !== $name) {
                $seat->update(['passenger_name' => $name]);
            }
        }
    }

    public function storeManualBooking(Request $request): JsonResponse
    {
        $request->validate([
            'schedule_id' => ['required', 'exists:trip_schedules,id'],
            'name' => ['nullable', 'required_without:customer_name', 'string', 'max:255'],
            'surname' => ['nullable', 'required_without:customer_name', 'string', 'max:255'],
            'customer_name' => ['nullable', 'required_without:name', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'passenger_count' => ['nullable', 'integer', 'min:1'],
            'passengers' => ['nullable', 'array', 'min:1'],
            'passengers.*.title' => ['nullable', 'string', 'max:50'],
            'passengers.*.name' => ['required_with:passengers', 'string', 'max:255'],
            'passengers.*.nickname' => ['nullable', 'string', 'max:100'],
            'passengers.*.phone' => ['nullable', 'string', 'max:20'],
            'passengers.*.email' => ['nullable', 'email', 'max:255'],
            'passengers.*.id_card' => ['nullable', 'string', 'max:100'],
            'passengers.*.birth_date' => ['nullable', 'date', 'before:today'],
            'passengers.*.blood_group' => ['nullable', 'string', 'max:10'],
            'passengers.*.allergies' => ['nullable', 'string'],
            'passengers.*.health_notes' => ['nullable', 'string'],
            'passengers.*.emergency_contact' => ['nullable', 'string', 'max:255'],
            'passengers.*.emergency_phone' => ['nullable', 'string', 'max:20'],
            'passengers.*.dive_cert_level' => ['nullable', 'string', 'max:255'],
            'passengers.*.cert_number' => ['nullable', 'string', 'max:255'],
            'passengers.*.weight' => ['nullable', 'numeric', 'min:0'],
            'passengers.*.halal_food' => ['nullable', 'boolean'],
            // จุดรับรายคน — กลุ่มเดียวกันขึ้นคนละจุดเป็นเรื่องปกติ
            'passengers.*.pickup_point_id' => ['nullable', 'integer'],
            'seat_ids' => ['nullable', 'array'],
            'seat_ids.*' => ['nullable', 'string', 'max:30'],
            'pickup_point_id' => ['nullable', 'exists:schedule_pickup_points,id'],
            'pickup_region' => ['nullable', 'string', 'max:80'],
            'vehicle_option_id' => ['nullable', 'integer', 'exists:schedule_vehicle_options,id'],
            'is_join_trip' => ['nullable', 'boolean'],
            'status' => ['required', 'in:pending,confirmed'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'payment_type' => ['nullable', 'in:full,deposit,installment'],
            'installment_count' => ['nullable', 'integer', 'min:2', 'max:'.PaymentQuote::MAX_INSTALLMENT_COUNT],
            'slip_image' => ['nullable', 'image', 'max:5120'],
            'transfer_date' => ['nullable', 'date'],
            'transfer_time' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'send_email' => ['nullable', 'boolean'],
            // ล็อกที่นั่งไว้ก่อน: ข้ามขั้นชำระเงิน แล้วกันที่นั่งไว้ให้ถึงเวลานี้
            'hold_until' => ['nullable', 'date'],
            'hold_note' => ['nullable', 'string', 'max:255'],
            // มาจากหน้า "ข้อมูลลูกค้า" — ปิดกลุ่มนั้นทันทีที่เปิดการจองให้แล้ว
            // หลายกลุ่มพร้อมกันได้ เพราะลูกค้าที่มาด้วยกันมักกดลิงก์ทีมงานคนละครั้ง
            // จนกลายเป็นคนละกลุ่ม แต่ต้องขึ้นรถคันเดียวกันและเลือกที่นั่งพร้อมกัน
            'intake_id' => ['nullable', 'exists:customer_intakes,id'],
            'intake_ids' => ['nullable', 'array', 'max:20'],
            'intake_ids.*' => ['integer', 'exists:customer_intakes,id'],
        ]);

        $schedule = TripSchedule::with(['trip', 'pickupPoints', 'vehicleOptions'])->findOrFail($request->schedule_id);
        $schedule->syncBookedSeats();

        $fullName = trim($request->input('customer_name') ?: trim(($request->input('name') ?? '').' '.($request->input('surname') ?? '')));
        $passengers = collect($request->input('passengers', []))
            ->filter(fn ($passenger) => filled($passenger['name'] ?? null))
            ->values();

        if ($passengers->isEmpty()) {
            $count = (int) ($request->input('passenger_count') ?: 1);
            $passengers = collect(range(1, $count))->map(fn ($number) => [
                'title' => '',
                'name' => $number === 1 ? $fullName : "ผู้ติดตามคนที่ {$number}",
                'phone' => $number === 1 ? $request->phone : null,
                'email' => $number === 1 ? $request->input('email') : null,
            ]);
        }

        $participantCount = $passengers->count();
        $isJoinTrip = (bool) $request->boolean('is_join_trip');
        $paymentType = $request->input('payment_type', 'full');
        $isPaid = $request->status === 'confirmed';

        // โหมด "ล็อกที่นั่งไว้ก่อน" — รับได้เฉพาะใบที่ยังไม่ได้ชำระ
        $holdUntil = $isPaid ? null : $this->resolveManualHoldUntil($request->input('hold_until'), $schedule);
        if ($holdUntil && $holdUntil->gt(now()->addDays(Booking::HOLD_MAX_DAYS))) {
            return $this->error('ล็อกที่นั่งได้ไม่เกิน '.Booking::HOLD_MAX_DAYS.' วัน', 422);
        }

        if ($isJoinTrip && ! $schedule->join_trip_enabled) {
            return $this->error('รอบเดินทางนี้ยังไม่ได้เปิดจอยทริป', 422);
        }

        // จอยทริปมีโควตาแยกจากที่นั่งบนรถ (ไม่กำหนดเพดาน = ไม่จำกัด)
        if ($isJoinTrip && ! $schedule->canFitJoinTrip($participantCount)) {
            return $this->error(
                $schedule->join_trip_available_seats > 0
                    ? 'จอยทริปรอบนี้เหลือ '.$schedule->join_trip_available_seats.' ที่ ไม่พอสำหรับ '.$participantCount.' ท่าน'
                    : 'จอยทริปรอบนี้เต็มแล้ว',
                422,
            );
        }

        // ผ่อนชำระเปิดให้อัตโนมัติเมื่อเหลือเวลาพอ (PaymentQuote) ไม่มีสวิตช์ที่รอบแล้ว
        $maxInstallmentCount = $isJoinTrip ? 0 : PaymentQuote::maxInstallmentCount($schedule);

        if ($paymentType === 'installment' && $maxInstallmentCount < 2) {
            return $this->error(
                $isJoinTrip
                    ? 'จอยทริปไม่รองรับการผ่อนชำระ'
                    : 'รอบนี้ใกล้วันเดินทางเกินกว่าจะผ่อนชำระได้แล้ว',
                422,
            );
        }

        if ($paymentType === 'deposit' && ($isJoinTrip || ! $schedule->deposit_enabled)) {
            return $this->error('รอบเดินทางนี้ไม่รองรับการชำระแบบมัดจำ', 422);
        }

        if (! $isJoinTrip && $schedule->available_seats < $participantCount) {
            return $this->error('ที่นั่งไม่เพียงพอสำหรับรอบเดินทางนี้', 422);
        }

        $seatIds = collect($request->input('seat_ids', []))->filter()->values();
        if (! $isJoinTrip && $seatIds->isNotEmpty()) {
            if ($seatIds->count() !== $participantCount) {
                return $this->error('จำนวนที่นั่งต้องเท่ากับจำนวนผู้เดินทาง', 422);
            }

            // กันที่นั่งซ้ำภายในคำขอเดียว — insert ตัวที่สองจะชนตัวแรกในธุรกรรมเดียวกัน
            $duplicateSeatIds = $seatIds->duplicates()->unique()->values();
            if ($duplicateSeatIds->isNotEmpty()) {
                return $this->error('เลือกที่นั่งซ้ำกัน: '.$duplicateSeatIds->join(', ').' กรุณาเลือกที่นั่งใหม่', 422);
            }

            $occupiedSeatIds = BookingSeat::where('schedule_id', $schedule->id)
                ->where('vehicle_option_id', (int) ($request->vehicle_option_id ?: 0))
                ->whereIn('seat_id', $seatIds)
                ->pluck('seat_id');

            if ($occupiedSeatIds->isNotEmpty()) {
                return $this->error('ที่นั่ง '.$occupiedSeatIds->join(', ').' ถูกจองแล้ว', 422);
            }
        }

        $pickupPoint = null;
        if (! $isJoinTrip && $request->filled('pickup_point_id')) {
            $pickupPoint = $schedule->pickupPoints->firstWhere('id', (int) $request->pickup_point_id);
            if (! $pickupPoint) {
                return $this->error('จุดรับไม่อยู่ในรอบเดินทางนี้', 422);
            }
        }

        // จุดรับรายคน — ไปรับที่รังสิตสองคน ที่ลาดพร้าวอีกคนในใบจองเดียวกันเป็น
        // เรื่องปกติ ราคาต่อคนจึงเป็นราคาของจุดที่คนนั้นขึ้น เพราะราคาจุดรับคือ
        // "ราคาต่อคนของโซนนั้น" ไม่ใช่ค่าบริการที่บวกเพิ่มจากราคารอบ
        // คนที่ไม่ได้เลือกเอง ใช้จุดรับหลักของใบจอง
        $passengerPickups = [];
        foreach ($passengers as $passenger) {
            if ($isJoinTrip) {
                $passengerPickups[] = null;

                continue;
            }

            $ownId = $passenger['pickup_point_id'] ?? null;
            if (blank($ownId)) {
                $passengerPickups[] = $pickupPoint;

                continue;
            }

            $own = $schedule->pickupPoints->firstWhere('id', (int) $ownId);
            if (! $own) {
                return $this->error(
                    'จุดรับของ '.($passenger['name'] ?? 'ผู้เดินทาง').' ไม่อยู่ในรอบเดินทางนี้',
                    422,
                );
            }

            $passengerPickups[] = $own;
        }

        // หัวการจองยังต้องมีจุดรับไว้ให้หน้าที่อ่านทีละใบ (ใบเสร็จ/อีเมล/สตาฟที่ยัง
        // fallback มาที่หัว) ถ้าแอดมินไม่ได้เลือกจุดหลัก ใช้จุดของคนแรกที่เลือกไว้
        $pickupPoint ??= collect($passengerPickups)->first(fn ($point) => $point !== null);

        // ประเภทรถที่แอดมินเลือกให้ลูกค้า (รอบที่วิ่งทั้งบัสและตู้) — ส่วนต่างคิด
        // ต่อคนบนราคาที่ได้จากจุดรับ เหมือนฝั่งลูกค้าใน BookingService
        $vehicleOption = null;
        if (! $isJoinTrip && $request->filled('vehicle_option_id')) {
            $vehicleOption = $schedule->vehicleOptions->firstWhere('id', (int) $request->vehicle_option_id);
            if (! $vehicleOption) {
                return $this->error('ประเภทรถที่เลือกไม่อยู่ในรอบเดินทางนี้', 422);
            }
            if (! $vehicleOption->canFit($participantCount)) {
                return $this->error($vehicleOption->label.'ของรอบนี้เหลือไม่พอสำหรับ '.$participantCount.' ท่าน', 422);
            }
        }

        $email = $request->input('email');
        $user = User::when($email, fn ($query) => $query->where('email', $email))
            ->when(! $email, fn ($query) => $query->where('phone', $request->phone))
            ->first();

        if (! $user) {
            $user = User::create([
                'name' => $fullName,
                'phone' => $request->phone,
                'email' => $email ?: 'manual_'.time().'_'.Str::random(4).'@luilaykhao.com',
                'password' => Hash::make(Str::random(16)),
            ]);
            $user->assignRole('customer');
        } else {
            $user->update(array_filter([
                'name' => $fullName ?: null,
                'phone' => $request->phone ?: null,
                'email' => $email ?: null,
            ], fn ($value) => filled($value)));
        }

        // ยอดรวมบวกทีละคนตามจุดที่คนนั้นขึ้น — คูณราคาเดียวด้วยจำนวนคนไม่ได้อีกแล้ว
        // เมื่อในใบเดียวกันมีหลายจุดรับ (ตรรกะเดียวกับฝั่งลูกค้าใน BookingService)
        $totalAmount = $isJoinTrip
            ? ($schedule->join_trip_price ?? $schedule->effective_price) * $participantCount
            : array_sum(array_map(
                fn ($point) => (float) ($point?->price ?? $schedule->effective_price),
                $passengerPickups,
            )) + ((float) ($vehicleOption?->price_adjustment ?? 0) * $participantCount);
        $installmentCount = null;
        $installmentIntervalDays = null;
        $installmentDueDates = [];
        $depositAmount = null;
        $balanceAmount = null;
        $balanceDueAt = null;
        $paidAmount = $isPaid ? $totalAmount : 0;

        if ($paymentType === 'installment') {
            $installmentCount = (int) ($request->input('installment_count') ?: $maxInstallmentCount);
            if ($installmentCount < 2 || $installmentCount > $maxInstallmentCount) {
                return $this->error("รอบนี้ผ่อนได้ 2-{$maxInstallmentCount} งวด", 422);
            }
            $installmentDueDates = PaymentQuote::installmentDueDates($schedule, $installmentCount);
            $installmentIntervalDays = PaymentQuote::installmentIntervalDays($schedule, $installmentCount);
            $paidAmount = $isPaid ? round($totalAmount / $installmentCount, 2) : 0;
        }

        if ($paymentType === 'deposit') {
            // ส่ง user id ไปด้วยเสมอ — ส่วนลดมัดจำตามระดับสมาชิกเป็นสิทธิ์ของลูกค้าคนนี้
            // และ PaymentQuote ฝั่งลูกค้าหักให้อยู่แล้ว ถ้าตรงนี้ไม่หัก ยอดมัดจำที่แอดมิน
            // บันทึกไว้จะไม่ตรงกับยอดที่หน้าชำระเงินของลูกค้าเรียกเก็บจริง
            $depositAmount = $schedule->resolveDepositAmount($totalAmount, $participantCount, $user->id);
            if (! $depositAmount || $depositAmount >= $totalAmount) {
                return $this->error('ไม่สามารถคำนวณยอดมัดจำสำหรับรอบเดินทางนี้', 422);
            }
            $balanceAmount = round($totalAmount - $depositAmount, 2);
            // กำหนดชำระยอดส่วนที่เหลือ: ก่อนเดินทาง 15 วัน (เหมือนฝั่งลูกค้าใน PaymentController)
            $balanceDueAt = $schedule->departure_date
                ? CarbonImmutable::parse($schedule->departure_date)->subDays(15)->startOfDay()
                : null;
            $paidAmount = $isPaid ? $depositAmount : 0;
        }

        $transferDt = $this->resolveManualTransferDatetime($request);
        if ($isPaid && (! $request->hasFile('slip_image') || ! $transferDt)) {
            return $this->error('กรุณาแนบสลิปและระบุวันเวลาที่โอนเงิน', 422);
        }
        $slipPath = null;
        if ($request->hasFile('slip_image')) {
            $slipPath = $request->file('slip_image')->store('slips/'.date('Y/m'), MediaDisk::slipDisk());
        }
        $paymentRef = $isPaid ? 'PAY-MANUAL-'.strtoupper(uniqid()) : null;

        try {
            $booking = DB::transaction(function () use (
                $request, $schedule, $user, $pickupPoint, $participantCount, $totalAmount,
                $paidAmount, $paymentType, $installmentCount, $installmentIntervalDays,
                $depositAmount, $balanceAmount, $balanceDueAt,
                $isPaid, $paymentRef, $slipPath, $transferDt, $isJoinTrip, $passengers, $seatIds,
                $holdUntil, $passengerPickups, $vehicleOption
            ) {
                // ล็อกรอบเดินทางแล้วตรวจที่นั่งซ้ำใต้ lock — ทำให้ check-แล้ว-insert เป็น atomic
                // กัน race กับการจองอื่น (ของลูกค้า/แอดมินคนอื่น) ที่ทำให้ชน unique constraint
                $lockedSchedule = TripSchedule::lockForUpdate()->findOrFail($schedule->id);

                if (! $isJoinTrip && $seatIds->isNotEmpty()) {
                    $occupied = BookingSeat::where('schedule_id', $lockedSchedule->id)
                        ->where('vehicle_option_id', (int) ($vehicleOption?->id ?? 0))
                        ->whereIn('seat_id', $seatIds->all())
                        ->pluck('seat_id')
                        ->unique()
                        ->values();

                    if ($occupied->isNotEmpty()) {
                        throw new \RuntimeException('ที่นั่ง '.$occupied->join(', ').' ถูกจองแล้ว');
                    }
                }

                $booking = Booking::create([
                    'booking_ref' => Booking::generateRef(),
                    'user_id' => $user->id,
                    'schedule_id' => $request->schedule_id,
                    'pickup_region' => $pickupPoint?->region ?: $request->input('pickup_region'),
                    'pickup_point_id' => $pickupPoint?->id,
                    'vehicle_option_id' => $vehicleOption?->id,
                    'vehicle_option_label' => $vehicleOption?->label,
                    'vehicle_option_adjustment' => $vehicleOption ? (float) $vehicleOption->price_adjustment : null,
                    'is_group' => $participantCount > 1,
                    'status' => $request->status,
                    'total_amount' => $totalAmount,
                    'paid_amount' => $paidAmount,
                    'payment_type' => $paymentType,
                    'installment_count' => $installmentCount,
                    'installment_interval_days' => $installmentIntervalDays,
                    'deposit_amount' => $depositAmount,
                    'balance_amount' => $balanceAmount,
                    'balance_due_at' => $balanceDueAt,
                    'payment_method' => $request->input('payment_method', 'promptpay'),
                    'payment_ref' => $paymentRef,
                    'paid_at' => $isPaid ? now() : null,
                    'slip_path' => $slipPath,
                    'transfer_datetime' => $transferDt,
                    'qr_code' => Booking::generateQrCode(),
                    'is_join_trip' => $isJoinTrip,
                    'hold_until' => $holdUntil,
                    'hold_note' => $holdUntil ? $request->input('hold_note') : null,
                    'hold_by_id' => $holdUntil ? $request->user()?->id : null,
                ]);

                $passengerModels = $passengers->map(function ($passenger, $index) use ($booking, $passengerPickups) {
                    return BookingPassenger::create([
                        'booking_id' => $booking->id,
                        // แถวรายคนคือสิ่งที่หน้าสตาฟและแอปคนขับอ่านก่อนหัวการจอง
                        'pickup_point_id' => ($passengerPickups[$index] ?? null)?->id,
                        'title' => $passenger['title'] ?? '',
                        'name' => $passenger['name'],
                        'nickname' => $passenger['nickname'] ?? null,
                        'phone' => $passenger['phone'] ?? null,
                        'email' => $passenger['email'] ?? null,
                        'id_card' => $passenger['id_card'] ?? null,
                        'birth_date' => $passenger['birth_date'] ?? null,
                        'blood_group' => $passenger['blood_group'] ?? null,
                        'allergies' => $passenger['allergies'] ?? null,
                        'health_notes' => $passenger['health_notes'] ?? null,
                        'emergency_contact' => $passenger['emergency_contact'] ?? null,
                        'emergency_phone' => $passenger['emergency_phone'] ?? null,
                        'dive_cert_level' => $passenger['dive_cert_level'] ?? null,
                        'cert_number' => $passenger['cert_number'] ?? null,
                        'weight' => $passenger['weight'] ?? null,
                        'halal_food' => (bool) ($passenger['halal_food'] ?? false),
                    ]);
                });

                if (! $isJoinTrip && $seatIds->isNotEmpty()) {
                    foreach ($seatIds as $index => $seatId) {
                        BookingSeat::create([
                            'booking_id' => $booking->id,
                            'schedule_id' => $lockedSchedule->id,
                            'vehicle_option_id' => (int) ($vehicleOption?->id ?? 0),
                            'seat_id' => $seatId,
                            'passenger_name' => $passengerModels->get($index)?->name,
                        ]);
                    }
                }

                if ($paymentType === 'installment' && $isPaid) {
                    $perInstallment = round($totalAmount / $installmentCount, 2);
                    for ($i = 1; $i <= $installmentCount; $i++) {
                        $amount = $i === $installmentCount
                            ? round($totalAmount - ($perInstallment * ($installmentCount - 1)), 2)
                            : $perInstallment;

                        InstallmentPayment::create([
                            'booking_id' => $booking->id,
                            'installment_no' => $i,
                            'amount' => $amount,
                            'due_date' => $installmentDueDates[$i - 1],
                            'status' => $i === 1 ? 'paid' : 'pending',
                            'payment_method' => $i === 1 ? $booking->payment_method : null,
                            'payment_ref' => $i === 1 ? $paymentRef : null,
                            'paid_at' => $i === 1 ? $booking->paid_at : null,
                            'slip_path' => $i === 1 ? $slipPath : null,
                            'transfer_datetime' => $i === 1 ? $transferDt : null,
                        ]);
                    }
                }

                $lockedSchedule->syncBookedSeats();
                // รอบที่เพิ่งล็อกมายังไม่ได้โหลดตัวเลือกรถ syncBookedSeats() จึงข้ามไป
                $lockedSchedule->syncVehicleOptionSeats();

                return $booking;
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $booking->load(['schedule.trip', 'schedule.vehicle', 'pickupPoint', 'user', 'passengers.pickupPoint', 'seats', 'installmentPayments']);

        if ($request->boolean('send_email', true) && $user->email && ! str_starts_with($user->email, 'manual_')) {
            app(MailService::class)->sendBookingCreatedEmail($booking);
            if ($booking->status === 'confirmed') {
                app(MailService::class)->sendPaymentConfirmedEmail($booking, $paymentType);
            }
        }

        if ($booking->status === 'confirmed') {
            app(SmsService::class)->sendPaymentConfirmed($booking, $paymentType);
        }

        $this->markIntakesBooked(
            array_merge((array) $request->input('intake_ids', []), [$request->input('intake_id')]),
            $booking,
        );

        $message = match (true) {
            $holdUntil !== null => 'ล็อกที่นั่งให้ลูกค้าแล้ว ถึง '.ThaiDate::shortTime($holdUntil->setTimezone('Asia/Bangkok')).' น.',
            $request->boolean('send_email', true) => 'บันทึกการจองและส่งอีเมลสำเร็จ',
            default => 'บันทึกการจองสำเร็จ',
        };

        return $this->success(new BookingResource($booking), $message, 201);
    }

    /**
     * ปิดกลุ่มข้อมูลลูกค้าที่ถูกดึงมาเปิดการจอง
     *
     * ไม่ลบแถวทิ้งทันทีทั้งที่ข้อมูลไปอยู่บนการจองแล้ว เพราะการจองที่เพิ่งเปิด
     * อาจถูกยกเลิกในไม่กี่ชั่วโมงถัดมา แล้วจะไม่เหลืออะไรให้ดึงกลับมาใช้เลย
     * งานลบอัตโนมัติเก็บให้เองหลังจากนี้ {@see CustomerIntake::CONVERTED_RETENTION_DAYS} วัน
     */
    /**
     * ปิดกลุ่มข้อมูลลูกค้าที่ถูกดึงมาเปิดการจองใบนี้ — ปิดทุกกลุ่มที่รวมกันมา
     * ไม่งั้นกลุ่มที่เหลือจะยังค้างเป็น "ใหม่" ให้แอดมินไล่ปิดเองทีหลัง
     *
     * @param  array<int, mixed>  $intakeIds
     */
    private function markIntakesBooked(array $intakeIds, Booking $booking): void
    {
        $ids = collect($intakeIds)->reject(fn ($id) => blank($id))->map(fn ($id) => (int) $id)->unique()->all();

        if ($ids === []) {
            return;
        }

        CustomerIntake::whereIn('id', $ids)->where('status', 'new')->update([
            'status' => 'booked',
            'booking_id' => $booking->id,
            'converted_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * POST /admin/bookings/{ref}/hold
     * ต่อเวลา/แก้เส้นตายของที่นั่งที่ทีมงานกันไว้ให้ลูกค้า
     *
     * ไม่มีทางเลือก "ปลดล็อก" โดยตั้งใจ: การจองใบนี้ถูกสร้างมานานแล้ว ถ้าล้าง
     * hold_until ทิ้ง ExpirePendingBookingsJob จะเห็นเป็นใบค้างเกินสิบนาทีแล้ว
     * ยกเลิกทันทีในนาทีถัดไป — ถ้าจะเลิกกันที่นั่งให้ใช้ยกเลิกการจองตามปกติ
     */
    public function updateBookingHold(Request $request, string $ref): JsonResponse
    {
        $request->validate([
            'hold_until' => ['required', 'date'],
            'hold_note' => ['nullable', 'string', 'max:255'],
        ]);

        $booking = Booking::with('schedule')->where('booking_ref', $ref)->firstOrFail();

        if ($booking->status !== 'pending') {
            return $this->error('ล็อกที่นั่งได้เฉพาะการจองที่ยังไม่ได้ชำระเงิน', 422);
        }

        if (! $booking->schedule) {
            return $this->error('การจองนี้ไม่มีรอบเดินทางแล้ว', 422);
        }

        $holdUntil = $this->resolveManualHoldUntil($request->input('hold_until'), $booking->schedule);

        if ($holdUntil->gt(now()->addDays(Booking::HOLD_MAX_DAYS))) {
            return $this->error('ล็อกที่นั่งได้ไม่เกิน '.Booking::HOLD_MAX_DAYS.' วัน', 422);
        }

        $booking->update([
            'hold_until' => $holdUntil,
            'hold_note' => $request->filled('hold_note') ? $request->input('hold_note') : $booking->hold_note,
            'hold_by_id' => $booking->hold_by_id ?: $request->user()?->id,
        ]);

        $booking->load(['schedule.trip', 'user', 'passengers', 'seats']);

        return $this->success(
            new BookingResource($booking),
            'ล็อกที่นั่งถึง '.ThaiDate::shortTime($holdUntil->setTimezone('Asia/Bangkok')).' น.',
        );
    }

    /**
     * เส้นตายล็อกที่นั่งที่แอดมินกรอก — ช่อง datetime-local ส่งค่าที่ไม่มีโซนเวลามา
     * ซึ่งคือเวลาไทยที่แอดมินเห็นบนจอ ไม่ใช่ UTC ตามที่ Carbon จะเดาให้
     */
    private function resolveManualHoldUntil(?string $value, TripSchedule $schedule): ?CarbonImmutable
    {
        if (! filled($value)) {
            return null;
        }

        $parsed = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value)
            ? Carbon::parse($value)
            : Carbon::parse($value, 'Asia/Bangkok');

        return CarbonImmutable::instance(Booking::capHoldUntil($parsed->utc(), $schedule));
    }

    private function resolveManualTransferDatetime(Request $request): ?string
    {
        $date = trim((string) $request->input('transfer_date', ''));
        $time = trim((string) $request->input('transfer_time', ''));

        if ($date === '') {
            return null;
        }

        if ($time === '') {
            $time = '00:00';
        }

        $format = substr_count($time, ':') === 2 ? 'Y-m-d H:i:s' : 'Y-m-d H:i';
        $parsed = CarbonImmutable::createFromFormat($format, "{$date} {$time}");

        return $parsed ? $parsed->format('Y-m-d H:i:s') : null;
    }

    public function deleteBooking(string $ref): JsonResponse
    {
        $booking = Booking::with(['seats', 'schedule', 'installmentPayments'])->where('booking_ref', $ref)->firstOrFail();
        $schedule = $booking->schedule;
        $bookedBefore = $schedule ? (int) $schedule->booked_seats : null;

        // 1. Delete associated files
        if ($booking->slip_path) {
            Storage::disk(MediaDisk::slipDisk())->delete($booking->slip_path);
        }

        // Also delete slip for installment payments
        foreach ($booking->installmentPayments as $payment) {
            if ($payment->slip_path) {
                Storage::disk(MediaDisk::slipDisk())->delete($payment->slip_path);
            }
        }

        // 2. Delete records
        $booking->seats()->delete();
        $booking->passengers()->delete();
        $booking->installmentPayments()->delete();
        $booking->delete();
        $schedule?->syncBookedSeats();

        if ($schedule) {
            // ที่นั่งว่างคืนมา — เสนอให้คนในคิวรอก่อน แล้วค่อยประกาศให้คนทั่วไป
            // (seatsFreed เงียบเองถ้ายังมีคิวรออยู่ ไม่ให้แซงคิว)
            ProcessWaitlistJob::dispatch($schedule->id);
            $this->seatNotifier->seatsFreed(
                $schedule->id,
                $bookedBefore,
                (int) $schedule->fresh()->booked_seats,
            );
        }

        return $this->success(null, 'ลบการจองสำเร็จ');
    }

    public function manifest(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->findOrFail($scheduleId);
        $schedule->syncBookedSeats(); // Auto-sync count when manifest is viewed

        $passengers = BookingPassenger::whereHas('booking', function ($q) use ($scheduleId) {
            $q->where('schedule_id', $scheduleId)
                ->whereIn('status', ['confirmed', 'pending']);
        })->with(['booking.seats', 'booking.pickupPoint', 'booking.user'])->get()->map(function ($p) {
            $p->is_join_trip = $p->booking->is_join_trip ?? false;
            // รอบที่วิ่งทั้งบัสและตู้ — ใบรายชื่อต้องบอกได้ว่าใครขึ้นคันไหน
            $p->vehicle_option_label = $p->booking->vehicle_option_label;

            return $p;
        });

        $regularPassengersCount = $passengers->where('is_join_trip', false)->count();
        $joinTripPassengersCount = $passengers->where('is_join_trip', true)->count();

        return $this->success([
            'schedule' => new TripScheduleResource($schedule),
            'passengers' => $passengers,
            'total_passengers' => $passengers->count(),
            'regular_passengers_count' => $regularPassengersCount,
            'join_trip_passengers_count' => $joinTripPassengersCount,
        ]);
    }

    // ─── Vehicles ─────────────────────────────────────────────

    public function vehicles(Request $request): JsonResponse
    {
        $query = Vehicle::withCount(['schedules', 'upcomingSchedules'])
            ->withMax('schedules', 'departure_date')
            ->with(['pickupPoints', 'driverUser', 'driver']);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // รถที่ "เลิกใช้แล้ว" = เคยมีรอบ แต่ไม่เหลือรอบข้างหน้าแล้ว
        // (หน้าจัดการยานพาหนะกรองฝั่งหน้าเว็บอยู่แล้ว พารามิเตอร์นี้ไว้ให้ผู้เรียกอื่นเลือกได้)
        if ($request->filled('status')) {
            $query->when(
                $request->status === 'retired',
                fn ($q) => $q->has('schedules')->doesntHave('upcomingSchedules'),
                fn ($q) => $q->where(fn ($w) => $w->has('upcomingSchedules')->orDoesntHave('schedules')),
            );
        }

        $vehicles = $query->orderBy('name')->paginate($request->get('per_page', 15));

        return $this->paginated($vehicles->through(fn ($v) => new VehicleResource($v)));
    }

    public function storeVehicle(StoreVehicleRequest $request): JsonResponse
    {
        $vehicle = new Vehicle($request->validated());

        // ถ้าเลือกคนขับจากทะเบียน ดึงชื่อ/เบอร์/รูปมาเก็บเป็น snapshot บนรถ
        $this->vehicleDriverService->applyDriverSnapshot($vehicle);
        $vehicle->save();

        return $this->success(new VehicleResource($vehicle->load(['pickupPoints', 'driverUser', 'driver'])), 'สร้างยานพาหนะสำเร็จ', 201);
    }

    public function updateVehicle(StoreVehicleRequest $request, int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);
        $previousPinAccountId = $vehicle->driver_user_id;
        $previousDriverId = $vehicle->driver_id;
        $vehicle->fill($request->validated());

        // ถ้าเลือกคนขับจากทะเบียน ดึงชื่อ/เบอร์/รูป (และบัญชีรหัส GPS ของเขา) มาทับบนรถ
        $this->vehicleDriverService->applyDriverSnapshot($vehicle);
        // เลิกผูก/เปลี่ยนคนขับแล้วต้องไม่ค้างบัญชีของคนเดิมไว้ ไม่งั้นเขายังเห็นรถคันนี้ในแอป
        $this->vehicleDriverService->detachInheritedPinAccount($vehicle, $previousDriverId);
        $vehicle->save();

        // ย้ายไปใช้บัญชีของคนขับในทะเบียนแล้ว บัญชีเดิมของรถคันนี้อาจไม่เหลือใครใช้
        if ($previousPinAccountId && $previousPinAccountId !== $vehicle->driver_user_id) {
            $this->vehicleDriverService->releaseOrphanedPin($previousPinAccountId);
        }

        // ซิงก์ชื่อ/เบอร์ของบัญชีคนขับที่ผูกไว้ ให้ตรงกับข้อมูลรถที่เพิ่งแก้
        $this->vehicleDriverService->syncDriverProfile($vehicle);

        return $this->success(new VehicleResource($vehicle->fresh()->load(['pickupPoints', 'driverUser', 'driver'])), 'อัปเดตยานพาหนะสำเร็จ');
    }

    /**
     * ตั้ง/เปลี่ยนรหัสส่ง GPS (PIN) ของคนขับประจำรถ — ใช้ล็อกอินที่ /driver/track
     */
    public function setVehicleDriverPin(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'driver_pin' => ['required', 'string', 'regex:/^\d{4,8}$/'],
        ]);

        $vehicle = Vehicle::findOrFail($id);

        try {
            $this->vehicleDriverService->setPin($vehicle, $validated['driver_pin']);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            new VehicleResource($vehicle->fresh()->load(['pickupPoints', 'driverUser', 'driver'])),
            'ตั้งรหัสส่ง GPS สำเร็จ',
        );
    }

    /**
     * สร้าง QR ให้คนขับสแกนเข้าแอปโดยไม่ต้องพิมพ์ PIN — ใช้ได้ครั้งเดียว หมดอายุใน 24 ชม.
     */
    public function createVehicleDriverLoginQr(int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);
        $driver = $vehicle->driverUser;

        if (! $driver || ! $driver->driver_pin_hash) {
            return $this->error('รถคันนี้ยังไม่มีบัญชีคนขับ กรุณาตั้งรหัสส่ง GPS ก่อน', 422);
        }

        $issued = $this->driverLoginCodes->issue($driver);

        return $this->success([
            'code' => $issued['code'],
            'expires_at' => $issued['expires_at']->toISOString(),
            'expires_at_label' => ThaiDate::full($issued['expires_at']).' เวลา '.$issued['expires_at']->format('H:i').' น.',
            'expires_in_seconds' => DriverLoginCodeService::TTL_HOURS * 3600,
            'driver_name' => $driver->name,
            'vehicle_name' => $vehicle->name,
        ], 'สร้าง QR เข้าสู่ระบบสำเร็จ');
    }

    /**
     * ลบรหัสส่ง GPS ของคนขับประจำรถ
     */
    public function clearVehicleDriverPin(int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);
        $this->vehicleDriverService->clearPin($vehicle);

        return $this->success(
            new VehicleResource($vehicle->fresh()->load(['pickupPoints', 'driverUser', 'driver'])),
            'ลบรหัสส่ง GPS แล้ว',
        );
    }

    public function deleteVehicle(int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);

        if ($vehicle->upcomingSchedules()->exists()) {
            return $this->error('ไม่สามารถลบยานพาหนะที่มีรอบเดินทางอยู่', 422);
        }

        // คืนรหัสหลังลบ ถ้าไม่มีรถคันอื่นและไม่มีคนขับในทะเบียนใช้บัญชีนั้นแล้ว
        // (ลบรถหนึ่งคันต้องไม่ไปล้างรหัสของคนขับที่ยังขับคันอื่นอยู่)
        $pinAccountId = $vehicle->driver_user_id;

        $vehicle->delete();

        app(VehicleDriverService::class)->releaseOrphanedPin($pinAccountId);

        return $this->success(null, 'ลบยานพาหนะสำเร็จ');
    }

    // ─── Drivers (ทะเบียนคนขับ) ───────────────────────────────

    public function drivers(Request $request): JsonResponse
    {
        $today = now('Asia/Bangkok')->toDateString();

        // ดึงข้อมูลรถและการใช้งานมาให้ครบในรอบเดียว — หน้าทะเบียนคนขับต้องตอบได้ทันที
        // ว่าคนขับคนนี้ขับรถคันไหน ทะเบียนอะไร และยังถูกใช้งานอยู่หรือเปล่า
        $query = Driver::withCount('vehicles')
            ->with(['pinUser', 'vehicles' => fn ($q) => $q->orderBy('name')->with('driverUser')])
            ->withMax(
                ['schedules as last_trip_date' => fn ($q) => $q->whereDate('return_date', '<', $today)],
                'return_date',
            )
            ->withCount(['schedules as upcoming_trips_count' => fn ($q) => $q->whereDate('departure_date', '>=', $today)]);

        if ($request->filled('search')) {
            $term = '%'.$request->search.'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('phone', 'like', $term)
                ->orWhere('license_number', 'like', $term)
                ->orWhereHas('vehicles', fn ($v) => $v->where('name', 'like', $term)->orWhere('license_plate', 'like', $term)));
        }

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        // คนขับที่ยังไม่ผูกรถ = ลบได้ (deleteDriver กันไม่ให้ลบคนที่ยังผูกรถอยู่)
        if ($request->boolean('unlinked_only')) {
            $query->whereDoesntHave('vehicles');
        }

        $drivers = $query->orderBy('name')->paginate($request->get('per_page', 20));

        return $this->paginated($drivers->through(fn ($d) => new DriverResource($d)));
    }

    public function storeDriver(Request $request): JsonResponse
    {
        $driver = Driver::create($this->validateDriver($request));

        return $this->success(
            new DriverResource($driver->fresh()->load('pinUser')->loadCount('vehicles')),
            'เพิ่มคนขับสำเร็จ',
            201,
        );
    }

    public function updateDriver(Request $request, int $id): JsonResponse
    {
        $driver = Driver::findOrFail($id);
        $driver->update($this->validateDriver($request));

        // อัปเดต snapshot บนรถทุกคันที่ผูกคนขับคนนี้ + ซิงก์บัญชี PIN
        $driver->vehicles()->get()->each(function (Vehicle $vehicle) {
            $this->vehicleDriverService->applyDriverSnapshot($vehicle);
            $vehicle->save();
            $this->vehicleDriverService->syncDriverProfile($vehicle);
        });

        return $this->success(
            new DriverResource($driver->fresh()->load('pinUser')->loadCount('vehicles')),
            'อัปเดตคนขับสำเร็จ',
        );
    }

    public function deleteDriver(int $id): JsonResponse
    {
        $driver = Driver::withCount('vehicles')->findOrFail($id);

        if ($driver->vehicles_count > 0) {
            return $this->error('ไม่สามารถลบคนขับที่ยังผูกกับรถอยู่ กรุณาเปลี่ยนคนขับของรถก่อน', 422);
        }

        // เอกสารประจำตัวไม่ควรค้างอยู่บนดิสก์หลังลบคนขับออกจากทะเบียนแล้ว
        if ($driver->license_photo) {
            Storage::disk(MediaDisk::slipDisk())->delete($driver->license_photo);
        }

        $driver->delete();

        return $this->success(null, 'ลบคนขับสำเร็จ');
    }

    /**
     * อัปโหลดรูปใบขับขี่ — เก็บบนดิสก์ส่วนตัวเหมือนสลิป ไม่ใช่คลังมีเดียสาธารณะ
     * เพราะเป็นเอกสารประจำตัว ส่งกลับเป็นลิงก์เซ็นชื่ออายุสั้น
     */
    public function uploadDriverLicensePhoto(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'license_photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        $driver = Driver::findOrFail($id);

        if ($driver->license_photo) {
            Storage::disk(MediaDisk::slipDisk())->delete($driver->license_photo);
        }

        $path = $request->file('license_photo')
            ->store(Driver::DOCUMENT_FOLDER.'/'.date('Y/m'), MediaDisk::slipDisk());

        $driver->forceFill(['license_photo' => $path])->save();

        return $this->success(
            new DriverResource($driver->fresh()->load('pinUser')->loadCount('vehicles')),
            'อัปโหลดรูปใบขับขี่สำเร็จ',
        );
    }

    public function deleteDriverLicensePhoto(int $id): JsonResponse
    {
        $driver = Driver::findOrFail($id);

        if ($driver->license_photo) {
            Storage::disk(MediaDisk::slipDisk())->delete($driver->license_photo);
            $driver->forceFill(['license_photo' => null])->save();
        }

        return $this->success(
            new DriverResource($driver->fresh()->load('pinUser')->loadCount('vehicles')),
            'ลบรูปใบขับขี่แล้ว',
        );
    }

    private function validateDriver(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'photo' => ['nullable', 'string', 'max:500'],
            'line_id' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],

            'license_number' => ['nullable', 'string', 'max:50'],
            'license_type' => ['nullable', 'string', 'max:50'],
            // ใบที่หมดอายุไปแล้วยังบันทึกได้ — ต้องคีย์ของจริงเพื่อให้ระบบเตือนว่าขาดอายุ
            'license_expires_at' => ['nullable', 'date'],

            'id_card' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:500'],
            'emergency_contact' => ['nullable', 'string', 'max:100'],
            'emergency_phone' => ['nullable', 'string', 'max:20'],
        ], [
            'birth_date.before' => 'วัน/เดือน/ปีเกิดไม่ถูกต้อง',
        ]);
    }

    // ─── Vehicle Pickup Points ────────────────────────────────

    public function vehiclePickupPoints(int $vehicleId): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($vehicleId);
        $points = $vehicle->pickupPoints()->orderBy('sort_order')->orderBy('id')->get();

        return $this->success($points->map(fn ($p) => [
            'id' => $p->id,
            'region' => $p->region,
            'region_label' => $p->region_label,
            'pickup_location' => $p->pickup_location,
            'map_url' => $p->map_url,
            'image_url' => $p->image_url,
            'latitude' => $p->latitude,
            'longitude' => $p->longitude,
            'notes' => $p->notes,
            'sort_order' => $p->sort_order,
        ]));
    }

    public function storeVehiclePickupPoint(Request $request, int $vehicleId): JsonResponse
    {
        Vehicle::findOrFail($vehicleId);

        $validated = $request->validate([
            'region' => ['required', 'string', 'max:50'],
            'region_label' => ['required', 'string', 'max:100'],
            'pickup_location' => ['required', 'string', 'max:255'],
            'map_url' => ['nullable', 'url', 'max:500'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $validated['vehicle_id'] = $vehicleId;

        $point = VehiclePickupPoint::create($validated);

        return $this->success([
            'id' => $point->id,
            'region' => $point->region,
            'region_label' => $point->region_label,
            'pickup_location' => $point->pickup_location,
            'map_url' => $point->map_url,
            'image_url' => $point->image_url,
            'latitude' => $point->latitude,
            'longitude' => $point->longitude,
            'notes' => $point->notes,
            'sort_order' => $point->sort_order,
        ], 'เพิ่มจุดรับผู้โดยสารสำเร็จ', 201);
    }

    public function updateVehiclePickupPoint(Request $request, int $vehicleId, int $pointId): JsonResponse
    {
        $point = VehiclePickupPoint::where('vehicle_id', $vehicleId)->findOrFail($pointId);

        $validated = $request->validate([
            'region' => ['sometimes', 'string', 'max:50'],
            'region_label' => ['sometimes', 'string', 'max:100'],
            'pickup_location' => ['sometimes', 'string', 'max:255'],
            'map_url' => ['nullable', 'url', 'max:500'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $point->update($validated);

        return $this->success([
            'id' => $point->id,
            'region' => $point->region,
            'region_label' => $point->region_label,
            'pickup_location' => $point->pickup_location,
            'map_url' => $point->map_url,
            'image_url' => $point->image_url,
            'latitude' => $point->latitude,
            'longitude' => $point->longitude,
            'notes' => $point->notes,
            'sort_order' => $point->sort_order,
        ], 'อัปเดตจุดรับผู้โดยสารสำเร็จ');
    }

    public function deleteVehiclePickupPoint(int $vehicleId, int $pointId): JsonResponse
    {
        $point = VehiclePickupPoint::where('vehicle_id', $vehicleId)->findOrFail($pointId);
        $point->delete();

        return $this->success(null, 'ลบจุดรับผู้โดยสารสำเร็จ');
    }

    // ─── Users ────────────────────────────────────────────────

    public function users(Request $request): JsonResponse
    {
        $query = User::withCount(['bookings', 'assignedSchedules'])->with('roles');

        // บัญชีคนขับ (role: driver) ใช้แค่ PIN ส่ง GPS — จัดการในหน้ายานพาหนะ ไม่แสดงที่นี่
        $query->whereDoesntHave('roles', fn ($r) => $r->where('name', VehicleDriverService::DRIVER_ROLE));

        if ($request->filled('role')) {
            $requestedRole = $request->role;
            if ($requestedRole === 'customer') {
                // Customers = users with no Spatie role, or explicitly assigned 'customer' role
                $query->where(function ($q) {
                    $q->whereDoesntHave('roles')
                        ->orWhereHas('roles', fn ($r) => $r->where('name', 'customer'));
                });
            } else {
                try {
                    $query->role($requestedRole);
                } catch (RoleDoesNotExist $e) {
                    // Role not created yet — return empty set without throwing
                    $query->whereRaw('0 = 1');
                }
            }
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->whereLike('name', "%{$request->search}%")
                    ->orWhereLike('email', "%{$request->search}%")
                    ->orWhereLike('phone', "%{$request->search}%");
            });
        }

        $users = $query->orderByDesc('created_at')->paginate($request->get('per_page', 15));

        return $this->paginated($users->through(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar_url' => $user->avatar_url,
                'social_provider' => $user->social_provider,
                'roles' => $user->roles->pluck('name'),
                'has_driver_pin' => ! empty($user->driver_pin_hash),
                'bookings_count' => $user->bookings_count,
                'assigned_schedules_count' => $user->assigned_schedules_count,
                'created_at' => $user->created_at?->toISOString(),
            ];
        }));
    }

    public function staffUsers(Request $request): JsonResponse
    {
        $this->ensureRoleNameExists('staff');

        $hasAssignmentsTable = Schema::hasTable('schedule_staff_assignments');
        $hasReviewsTable = Schema::hasTable('staff_reviews');

        $query = $this->usersWithRoleName('staff');

        if ($hasAssignmentsTable) {
            $query->withCount('assignedSchedules');
        }

        if ($hasReviewsTable) {
            $query->withCount('staffReviewsReceived')
                ->withAvg('staffReviewsReceived as avg_staff_rating', 'rating');
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->whereLike('name', "%{$request->search}%")
                    ->orWhereLike('email', "%{$request->search}%")
                    ->orWhereLike('phone', "%{$request->search}%");
            });
        }

        $staff = $query->orderBy('name')->paginate($request->get('per_page', 30));

        return $this->paginated($staff->through(fn ($user) => [
            'id' => $user->id,
            'name' => $user->name,
            'nickname' => $user->nickname,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar_url' => $user->avatar_url,
            'assigned_schedules_count' => $user->assigned_schedules_count ?? 0,
            'total_staff_reviews' => $user->staff_reviews_received_count ?? 0,
            'avg_staff_rating' => $user->avg_staff_rating ? round((float) $user->avg_staff_rating, 2) : null,
        ]));
    }

    public function staffRoster(Request $request): JsonResponse
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->from)->startOfDay()
            : now()->startOfDay();

        $to = $request->filled('to')
            ? Carbon::parse($request->to)->endOfDay()
            : $from->copy()->addDays(29)->endOfDay();

        if (! Schema::hasTable('schedule_staff_assignments')) {
            return $this->success(['staff' => [], 'schedules' => [], 'assignments' => []]);
        }

        $schedules = TripSchedule::with(['trip', 'vehicle'])
            ->whereBetween('departure_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('departure_date')
            ->get();

        $scheduleIds = $schedules->pluck('id');

        $assignments = ScheduleStaffAssignment::with('user')
            ->whereIn('schedule_id', $scheduleIds)
            ->whereNull('released_at')
            ->get()
            ->groupBy('schedule_id');

        $staffIds = $assignments->flatten()->pluck('user_id')->unique();
        $staffUsers = User::whereIn('id', $staffIds)->orderBy('name')->get();

        return $this->success([
            'staff' => $staffUsers->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'nickname' => $u->nickname,
                'phone' => $u->phone,
                'avatar_url' => $u->avatar_url,
            ])->values(),
            'schedules' => $schedules->map(fn ($s) => [
                'id' => $s->id,
                'trip_title' => $s->trip?->title ?? 'ไม่ระบุทริป',
                'trip_location' => $s->trip?->location,
                'departure_date' => $s->departure_date?->toDateString(),
                'return_date' => $s->return_date?->toDateString(),
                'status' => $s->status,
                'transport_type' => $s->transport_type,
                'vehicle_name' => $s->vehicle?->name,
            ])->values(),
            'assignments' => $assignments->map(fn ($rows) => $rows->pluck('user_id')->values())->toArray(),
        ]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:6'],
            'driver_pin' => ['nullable', 'string', 'regex:/^\d{4,8}$/'],
            'role' => ['required', 'in:admin,operator,staff,customer'],
        ]);

        // PIN ใช้เลขชุดเดียวกับคนขับประจำรถ — ถ้าซ้ำ pinLogin จะพาไปเข้าบัญชีผิดคน
        if (! empty($validated['driver_pin'])) {
            try {
                app(VehicleDriverService::class)->assertPinAvailable($validated['driver_pin'], null);
            } catch (\RuntimeException $e) {
                return $this->error($e->getMessage(), 422);
            }
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'driver_pin_hash' => ! empty($validated['driver_pin'])
                ? Hash::make($validated['driver_pin'])
                : null,
        ]);

        $user->assignRole($this->ensureAssignableRole($validated['role']));

        return $this->success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => $user->roles->pluck('name'),
            'has_driver_pin' => ! empty($user->driver_pin_hash),
        ], 'สร้างผู้ใช้สำเร็จ', 201);
    }

    public function updateUser(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'unique:users,email,'.$id],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:6'],
            'driver_pin' => ['nullable', 'string', 'regex:/^\d{4,8}$/'],
            'role' => ['sometimes', 'in:admin,operator,staff,customer'],
        ]);

        $userData = collect($validated)->except(['password', 'driver_pin', 'role'])->toArray();
        if (! empty($validated['password'])) {
            $userData['password'] = Hash::make($validated['password']);
        }
        if (isset($validated['driver_pin']) && $validated['driver_pin'] !== '') {
            // PIN ใช้เลขชุดเดียวกับคนขับประจำรถ — ถ้าซ้ำ pinLogin จะพาไปเข้าบัญชีผิดคน
            try {
                app(VehicleDriverService::class)->assertPinAvailable($validated['driver_pin'], $user->id);
            } catch (\RuntimeException $e) {
                return $this->error($e->getMessage(), 422);
            }

            $userData['driver_pin_hash'] = Hash::make($validated['driver_pin']);
        }

        $user->update($userData);

        if (isset($validated['role'])) {
            $user->syncRoles([$this->ensureAssignableRole($validated['role'])]);
        }

        return $this->success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => $user->fresh()->roles->pluck('name'),
            'has_driver_pin' => ! empty($user->driver_pin_hash),
        ], 'อัปเดตผู้ใช้สำเร็จ');
    }

    public function deleteUser(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            return $this->error('ไม่สามารถลบบัญชีตัวเอง', 422);
        }

        $hasBookings = $user->bookings()
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists();

        if ($hasBookings) {
            return $this->error('ไม่สามารถลบผู้ใช้ที่มีการจองอยู่', 422);
        }

        $user->delete();

        return $this->success(null, 'ลบผู้ใช้สำเร็จ');
    }

    // ─── Schedule Pickup Points ───────────────────────────────

    public function pickupPoints(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $points = $schedule->pickupPoints()->orderBy('sort_order')->orderBy('id')->get();

        return $this->success(SchedulePickupPointResource::collection($points));
    }

    public function storePickupPoint(Request $request, int $scheduleId): JsonResponse
    {
        TripSchedule::findOrFail($scheduleId);

        $validated = $request->validate([
            'region' => ['required', 'string', 'max:50'],
            'region_label' => ['required', 'string', 'max:100'],
            'pickup_location' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'map_url' => ['nullable', 'url', 'max:500'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:500'],
            'pickup_time' => ['nullable', 'date_format:H:i'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // Prevent duplicate pickup points (same schedule + region + location)
        $exists = SchedulePickupPoint::where('schedule_id', $scheduleId)
            ->where('region', $validated['region'])
            ->where('pickup_location', $validated['pickup_location'])
            ->exists();

        if ($exists) {
            return $this->error('จุดรับนี้มีอยู่แล้วในรอบเดินทางนี้', 422);
        }

        $validated['schedule_id'] = $scheduleId;

        $point = SchedulePickupPoint::create($validated);

        return $this->success(new SchedulePickupPointResource($point), 'บันทึกจุดรับผู้โดยสารสำเร็จ', 201);
    }

    // Copy only the images from this schedule's pickup points onto matching
    // points (same region + location) in other rounds. This is an UPDATE-only
    // operation — it never creates or deletes points, so bookings that reference
    // a pickup_point_id are never affected.
    public function syncPickupImages(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'schedule_ids' => ['required', 'array', 'min:1'],
            'schedule_ids.*' => ['integer'],
        ]);

        $source = TripSchedule::with('pickupPoints')->findOrFail($scheduleId);

        // Map of "region::location" => image_url for source points that have an image
        $imageMap = [];
        foreach ($source->pickupPoints as $p) {
            if (filled($p->image_url)) {
                $imageMap[$p->region.'::'.$p->pickup_location] = $p->image_url;
            }
        }

        if (empty($imageMap)) {
            return $this->error('รอบต้นทางยังไม่มีรูปจุดรับให้ซิงค์', 422);
        }

        $updatedPoints = 0;
        $updatedSchedules = 0;
        $targetIds = array_values(array_diff($validated['schedule_ids'], [$scheduleId]));

        foreach ($targetIds as $targetId) {
            $changed = 0;
            $points = SchedulePickupPoint::where('schedule_id', $targetId)->get();
            foreach ($points as $point) {
                $key = $point->region.'::'.$point->pickup_location;
                if (isset($imageMap[$key]) && $point->image_url !== $imageMap[$key]) {
                    $point->update(['image_url' => $imageMap[$key]]);
                    $changed++;
                }
            }
            if ($changed > 0) {
                $updatedPoints += $changed;
                $updatedSchedules++;
            }
        }

        return $this->success([
            'updated_schedules' => $updatedSchedules,
            'updated_points' => $updatedPoints,
        ], "ซิงค์รูปจุดรับไป {$updatedSchedules} รอบสำเร็จ");
    }

    /**
     * คัดลอกเวลาขึ้นรถของรอบนี้ไปทับจุดรับที่ชื่อตรงกันในรอบอื่น
     *
     * ทริปเดียวกันมักใช้เวลานัดชุดเดิมทุกรอบ แต่เดิมต้องไล่แก้ทีละรอบทีละจุด
     * จับคู่ด้วย region + ชื่อจุดแบบเดียวกับการซิงค์รูป และไม่สร้าง/ไม่ลบจุดไหนเลย
     * ใบจองที่ผูกกับจุดรับเดิมจึงไม่ขยับตาม
     *
     * ไม่ส่ง schedule_ids มา = ทุกรอบที่เหลือของทริปเดียวกัน (ปุ่ม "คัดลอกเวลาไปทุกรอบ")
     * ส่งมาก็ยังถูกกรองให้เหลือเฉพาะรอบของทริปนี้ — เวลานัดของทริปหนึ่งไม่ควรรั่ว
     * ไปทับอีกทริปที่บังเอิญมีจุดรับชื่อเดียวกัน
     */
    public function syncPickupTimes(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'schedule_ids' => ['nullable', 'array'],
            'schedule_ids.*' => ['integer'],
        ]);

        $source = TripSchedule::with('pickupPoints')->findOrFail($scheduleId);

        $timeMap = [];
        foreach ($source->pickupPoints as $point) {
            if (filled($point->pickup_time)) {
                $timeMap[$point->region.'::'.$point->pickup_location] = $point->pickup_time;
            }
        }

        if (empty($timeMap)) {
            return $this->error('รอบต้นทางยังไม่ได้กรอกเวลาขึ้นรถให้จุดไหนเลย', 422);
        }

        $targetIds = TripSchedule::where('trip_id', $source->trip_id)
            ->whereKeyNot($source->id)
            ->when(
                filled($validated['schedule_ids'] ?? null),
                fn ($query) => $query->whereIn('id', $validated['schedule_ids'])
            )
            ->pluck('id');

        $updatedPoints = 0;
        $updatedSchedules = 0;

        foreach ($targetIds as $targetId) {
            $changed = 0;

            foreach (SchedulePickupPoint::where('schedule_id', $targetId)->get() as $point) {
                $time = $timeMap[$point->region.'::'.$point->pickup_location] ?? null;

                if ($time !== null && $point->pickup_time !== $time) {
                    $point->update(['pickup_time' => $time]);
                    $changed++;
                }
            }

            if ($changed > 0) {
                $updatedPoints += $changed;
                $updatedSchedules++;
            }
        }

        return $this->success([
            'updated_schedules' => $updatedSchedules,
            'updated_points' => $updatedPoints,
        ], "คัดลอกเวลาขึ้นรถไป {$updatedSchedules} รอบสำเร็จ");
    }

    public function updatePickupPoint(Request $request, int $scheduleId, int $pointId): JsonResponse
    {
        $point = SchedulePickupPoint::where('schedule_id', $scheduleId)->findOrFail($pointId);

        $validated = $request->validate([
            'region' => ['sometimes', 'string', 'max:50'],
            'region_label' => ['sometimes', 'string', 'max:100'],
            'pickup_location' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'map_url' => ['nullable', 'url', 'max:500'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:500'],
            'pickup_time' => ['nullable', 'date_format:H:i'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $point->update($validated);

        return $this->success(new SchedulePickupPointResource($point->fresh()), 'อัปเดตจุดรับผู้โดยสารสำเร็จ');
    }

    public function deletePickupPoint(int $scheduleId, int $pointId): JsonResponse
    {
        $point = SchedulePickupPoint::where('schedule_id', $scheduleId)->findOrFail($pointId);
        $point->delete();

        return $this->success(null, 'ลบจุดรับผู้โดยสารสำเร็จ');
    }

    // ─── Schedule Vehicle Options ─────────────────────────────
    // รอบที่วิ่งหลายคันคนละราคา (บัส/ตู้) — ลูกค้าเลือกเองในหน้าจอง

    public function vehicleOptions(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('vehicleOptions')->findOrFail($scheduleId);
        $schedule->syncVehicleOptionSeats();

        return $this->success(ScheduleVehicleOptionResource::collection($schedule->vehicleOptions));
    }

    public function storeVehicleOption(Request $request, int $scheduleId): JsonResponse
    {
        TripSchedule::findOrFail($scheduleId);

        $validated = $request->validate($this->vehicleOptionRules());
        $validated['schedule_id'] = $scheduleId;

        $option = ScheduleVehicleOption::create($validated);

        return $this->success(new ScheduleVehicleOptionResource($option), 'เพิ่มประเภทรถสำเร็จ', 201);
    }

    public function updateVehicleOption(Request $request, int $scheduleId, int $optionId): JsonResponse
    {
        $option = ScheduleVehicleOption::where('schedule_id', $scheduleId)->findOrFail($optionId);

        $validated = $request->validate($this->vehicleOptionRules(updating: true));

        // ลดโควตาต่ำกว่าคนที่จองคันนี้ไปแล้วไม่ได้ — ที่นั่งที่ขายไปแล้วต้องมีที่นั่งจริงรองรับ
        if (array_key_exists('seats', $validated)
            && $validated['seats'] !== null
            && $validated['seats'] < (int) $option->booked_seats) {
            return $this->error(
                'ลดที่นั่งเหลือ '.$validated['seats'].' ไม่ได้ เพราะมีคนจอง'.$option->label.'ไปแล้ว '.$option->booked_seats.' ที่',
                422
            );
        }

        $option->update($validated);

        return $this->success(new ScheduleVehicleOptionResource($option->fresh()), 'อัปเดตประเภทรถสำเร็จ');
    }

    public function deleteVehicleOption(int $scheduleId, int $optionId): JsonResponse
    {
        $option = ScheduleVehicleOption::where('schedule_id', $scheduleId)->findOrFail($optionId);

        // ใบจองที่เลือกคันนี้ไว้ยังอ้างถึงอยู่ — ปิดการใช้งานแทนการลบ เพื่อให้
        // ใบจองเดิมยังชี้ไปที่ตัวเลือกเดิมได้ และคนใหม่เลือกไม่ได้แล้ว
        // (ที่นั่งผูกกับคัน ลบทิ้งเลยจะทำให้แถวใน booking_seats ชี้คันที่ไม่มีอยู่
        //  แล้วที่นั่งที่ลูกค้าจ่ายไปแล้วหลุดจากผัง)
        $blocking = $option->bookings()
            ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
            ->orderBy('id')
            ->pluck('booking_ref');

        if ($blocking->isNotEmpty()) {
            $option->update(['is_active' => false]);

            // บอกไปเลยว่าติดใบไหน แอดมินจะได้เข้าไปย้ายคันในหน้าแก้ไขการจองแล้ว
            // กลับมาลบได้ โดยไม่ต้องไล่หาเองว่าใครจองคันนี้ไว้
            $refs = $blocking->take(5)->join(', ')
                .($blocking->count() > 5 ? ' และอีก '.($blocking->count() - 5).' ใบ' : '');

            return $this->success(
                new ScheduleVehicleOptionResource($option->fresh()),
                'มีการจองที่เลือก'.$option->label.'อยู่ ('.$refs.') จึงปิดไม่ให้เลือกใหม่แทนการลบ'
                .' — ย้ายใบจองเหล่านี้ไปคันอื่นในหน้าแก้ไขการจองก่อน แล้วจึงลบได้'
            );
        }

        $option->delete();

        return $this->success(null, 'ลบประเภทรถสำเร็จ');
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function vehicleOptionRules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'label' => [$required, 'string', 'max:60'],
            'transport_type' => ['nullable', 'string', Rule::in(Vehicle::TYPES)],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            // ติดลบได้ = คันนี้ถูกกว่าราคาปกติของรอบ
            'price_adjustment' => [$required, 'numeric', 'between:-100000,100000'],
            'seats' => ['nullable', 'integer', 'min:0', 'max:200'],
            // ปิด = ทีมงานจัดที่นั่งหน้างาน คันนั้นข้ามขั้นเลือกที่นั่งไปเลย
            'seat_selection' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * บันทึกเส้นทางเดินรถที่แอดมินวาดเอง — ลำดับพิกัดที่คลิกบนแผนที่
     * ตั้งแต่ 2 จุดขึ้นไปจะ override เส้นจาก Google ในหน้าลูกค้า; ส่ง [] เพื่อลบ
     * แล้วกลับไปใช้เส้นอัตโนมัติ
     * PUT /api/v1/admin/schedules/{id}/route
     */
    public function updateScheduleRoute(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);

        $validated = $request->validate([
            'points' => ['present', 'array', 'max:2000'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $points = array_values(array_map(fn ($p) => [
            'lat' => round((float) $p['lat'], 6),
            'lng' => round((float) $p['lng'], 6),
        ], $validated['points']));

        if (count($points) === 1) {
            return $this->error('เส้นทางต้องมีอย่างน้อย 2 จุด หรือส่งค่าว่างเพื่อลบเส้นทาง', 422);
        }

        $schedule->custom_route = $points === [] ? null : $points;
        $schedule->save();

        return $this->success([
            'schedule_id' => $schedule->id,
            'custom_route' => $schedule->custom_route,
            'distance' => count($points) >= 2 ? Polyline::pathDistanceMeters($points) : 0,
        ], $points === [] ? 'ลบเส้นทางที่วาดเองแล้ว กลับไปใช้เส้นทางอัตโนมัติ' : 'บันทึกเส้นทางเดินรถสำเร็จ');
    }

    /**
     * รอบอื่นของทริปเดียวกันที่มีจุดรับ — ใช้เป็นตัวเลือกให้ปุ่ม "ดึงจุดรับจากรอบอื่น"
     */
    public function pickupCopySources(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);

        $sources = TripSchedule::where('trip_id', $schedule->trip_id)
            ->where('id', '!=', $scheduleId)
            ->whereHas('pickupPoints')
            ->withCount('pickupPoints')
            ->orderByDesc('departure_date')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'departure_date' => $s->departure_date?->toDateString(),
                'departure_date_label' => $s->departure_date ? ThaiDate::short($s->departure_date) : null,
                'pickup_points_count' => $s->pickup_points_count,
            ]);

        return $this->success($sources);
    }

    /**
     * ดึง (คัดลอก) จุดรับจากรอบต้นทางมาไว้ในรอบนี้ — ข้ามจุดที่ซ้ำอยู่แล้ว
     */
    public function copyPickupPointsFrom(Request $request, int $scheduleId): JsonResponse
    {
        $target = TripSchedule::findOrFail($scheduleId);

        $validated = $request->validate([
            'source_schedule_id' => ['required', 'integer', 'exists:trip_schedules,id'],
        ]);

        if ((int) $validated['source_schedule_id'] === $scheduleId) {
            return $this->error('ไม่สามารถคัดลอกจากรอบเดียวกันได้', 422);
        }

        $source = TripSchedule::with('pickupPoints')->findOrFail($validated['source_schedule_id']);

        $copied = $this->clonePickupPoints($source, $target);

        $points = $target->pickupPoints()->orderBy('sort_order')->orderBy('id')->get();

        return $this->success([
            'copied' => $copied,
            'pickup_points' => SchedulePickupPointResource::collection($points),
        ], $copied > 0
            ? "คัดลอกจุดรับ {$copied} จุดสำเร็จ"
            : 'ไม่มีจุดรับใหม่ให้คัดลอก (ซ้ำกับที่มีอยู่แล้วทั้งหมด)');
    }

    // ─── Pickup Point Image Upload ────────────────────────────
    public function uploadPickupPointImage(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,webp,heic,heif', 'max:15360'], // max 15MB
        ]);

        $file = $request->file('file');

        $disk = MediaDisk::name();

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $filename = date('YmdHis').'_'.Str::random(10).'.'.$ext;
        $path = 'pickup-points/'.$filename;

        $stream = fopen($file->getRealPath(), 'r');
        Storage::disk($disk)->put($path, $stream, [
            'visibility' => 'public',
            'ContentType' => $file->getMimeType(),
        ]);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $this->success([
            'url' => Storage::disk($disk)->url($path),
        ], 'อัปโหลดรูปจุดรับผู้โดยสารสำเร็จ', 201);
    }

    // Distinct pickup point images already in use (schedules + vehicles),
    // so admins can reuse a previously uploaded image instead of re-uploading.
    public function pickupPointImages(): JsonResponse
    {
        $rows = SchedulePickupPoint::whereNotNull('image_url')->where('image_url', '!=', '')
            ->orderByDesc('id')
            ->get(['image_url', 'pickup_location'])
            ->concat(
                VehiclePickupPoint::whereNotNull('image_url')->where('image_url', '!=', '')
                    ->orderByDesc('id')
                    ->get(['image_url', 'pickup_location'])
            );

        $images = $rows
            ->unique('image_url')
            ->values()
            ->map(fn ($r) => [
                'url' => $r->image_url,
                'label' => $r->pickup_location,
            ]);

        return $this->success($images);
    }

    // Distinct add-on (must-know) item images already used across trips, so an
    // admin can reuse e.g. a "tent" photo instead of re-uploading it each time.
    public function mustKnowImages(): JsonResponse
    {
        $images = collect();

        Trip::whereNotNull('must_know')->get(['must_know'])->each(function ($trip) use ($images) {
            foreach (($trip->must_know['items'] ?? []) as $item) {
                $url = is_array($item) ? ($item['image_url'] ?? null) : null;
                if (is_string($url) && $url !== '') {
                    $images->push([
                        'url' => $url,
                        'label' => is_array($item) ? (string) ($item['name'] ?? '') : '',
                    ]);
                }
            }
        });

        return $this->success($images->unique('url')->values());
    }

    // ─── Media Upload ─────────────────────────────────────────

    /** Upload size ceilings in bytes — video is far bigger than any image needs. */
    private const MEDIA_MAX_VIDEO_BYTES = 204800 * 1024;   // 200MB

    private const MEDIA_MAX_IMAGE_BYTES = 15360 * 1024;    // 15MB

    /** Content types accepted by both the direct and the presigned upload paths. */
    private const MEDIA_CONTENT_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/x-m4v' => 'm4v',
        'video/x-msvideo' => 'avi',
    ];

    /**
     * Hand the browser a presigned PUT so a large file goes straight to R2
     * instead of through PHP. Routing a 200MB clip through the server meant
     * uploading it twice over (client → server → R2) and squeezing it past
     * post_max_size and the proxy body limit; this does neither.
     *
     * Falls back to `supported: false` on disks that can't presign (local dev
     * runs on the 'public' disk), which tells the client to POST as before.
     */
    public function presignMedia(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', Rule::in(array_keys(self::MEDIA_CONTENT_TYPES))],
            'size' => ['required', 'integer', 'min:1'],
        ]);

        $isVideo = str_starts_with($data['content_type'], 'video/');
        $maxBytes = $isVideo ? self::MEDIA_MAX_VIDEO_BYTES : self::MEDIA_MAX_IMAGE_BYTES;

        if ($data['size'] > $maxBytes) {
            return $this->error(
                $isVideo ? 'วิดีโอต้องมีขนาดไม่เกิน 200MB' : 'รูปภาพต้องมีขนาดไม่เกิน 15MB',
                422
            );
        }

        $disk = MediaDisk::name();

        if ($disk !== 'r2') {
            return $this->success(['supported' => false], 'ดิสก์นี้ไม่รองรับการอัปโหลดตรง');
        }

        // Build the key ourselves — never trust the client's filename in a path.
        $ext = self::MEDIA_CONTENT_TYPES[$data['content_type']];
        $path = 'media/'.time().'_'.Str::random(8).'.'.$ext;

        // Only 'host' ends up in X-Amz-SignedHeaders, so don't sign a content
        // type — the browser sends the File's own and R2 stores that.
        $signed = Storage::disk($disk)->temporaryUploadUrl($path, now()->addMinutes(30));

        // Whitelist, not blacklist. The signer hands back every header it built
        // the request with — Host, X-Amz-Content-Sha256, and whatever else the
        // SDK adds — and any one of them the browser forwards has to appear in
        // the bucket's CORS AllowedHeaders or the preflight is refused outright.
        // Nothing but the payload's own type is needed here (SignedHeaders=host
        // means the signature covers no header at all), so send only that.
        $headers = collect($signed['headers'])
            ->filter(fn ($v, $k) => strtolower($k) === 'content-type')
            ->map(fn ($v) => is_array($v) ? implode(', ', $v) : $v)
            ->all();

        return $this->success([
            'supported' => true,
            'path' => $path,
            'upload_url' => $signed['url'],
            'headers' => $headers,
        ]);
    }

    /**
     * Called once the browser's PUT lands. The size the client declared at
     * presign time is only a claim, so re-check the object that actually
     * arrived and drop it if it's over the cap.
     */
    public function confirmMedia(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:255'],
        ]);

        $path = $data['path'];

        // Confine confirmations to keys presignMedia could have issued.
        if (! preg_match('#^media/\d+_[A-Za-z0-9]+\.[A-Za-z0-9]+$#', $path)) {
            return $this->error('เส้นทางไฟล์ไม่ถูกต้อง', 422);
        }

        $disk = Storage::disk(MediaDisk::name());

        if (! $disk->exists($path)) {
            return $this->error('ไม่พบไฟล์ที่อัปโหลด', 404);
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $isVideo = in_array($ext, ['mp4', 'mov', 'm4v', 'avi'], true);
        $maxBytes = $isVideo ? self::MEDIA_MAX_VIDEO_BYTES : self::MEDIA_MAX_IMAGE_BYTES;

        if ($disk->size($path) > $maxBytes) {
            $disk->delete($path);

            return $this->error(
                $isVideo ? 'วิดีโอต้องมีขนาดไม่เกิน 200MB' : 'รูปภาพต้องมีขนาดไม่เกิน 15MB',
                422
            );
        }

        return $this->success([
            'url' => $disk->url($path),
            'filename' => basename($path),
        ], 'อัปโหลดสื่อสำเร็จ');
    }

    public function uploadMedia(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,webp,gif,mp4,mov,avi'],
        ]);

        $file = $request->file('file');
        $isVideo = str_starts_with((string) $file->getMimeType(), 'video/');

        // วิดีโอไฟล์ใหญ่ได้ถึง 200MB, รูปภาพจำกัดที่ 15MB
        $request->validate([
            'file' => ['max:'.($isVideo ? 204800 : 15360)],
        ], [
            'file.max' => $isVideo
                ? 'วิดีโอต้องมีขนาดไม่เกิน 200MB'
                : 'รูปภาพต้องมีขนาดไม่เกิน 15MB',
        ]);

        $filename = time().'_'.Str::random(8).'.'.$file->getClientOriginalExtension();

        $path = $file->storeAs('media', $filename, MediaDisk::name());
        $url = Storage::disk(MediaDisk::name())->url($path);

        return $this->success([
            'url' => $url,
            'filename' => $filename,
        ], 'อัปโหลดสื่อสำเร็จ');
    }

    public function listMedia(): JsonResponse
    {
        $files = Storage::disk(MediaDisk::name())->files('media');

        $extractFilename = function ($url) {
            return basename(parse_url((string) $url, PHP_URL_PATH));
        };

        // Collect all in-use filenames from Trips
        $trips = Trip::select('cover_image', 'gallery')->get();
        $inUseFilenames = collect();
        foreach ($trips as $trip) {
            if ($trip->cover_image) {
                $inUseFilenames->push($extractFilename($trip->cover_image));
            }
            if ($trip->gallery && is_array($trip->gallery)) {
                foreach ($trip->gallery as $img) {
                    $inUseFilenames->push($extractFilename($img));
                }
            }
        }

        // Collect from Reviews
        $reviews = Review::select('images')->get();
        foreach ($reviews as $review) {
            if ($review->images && is_array($review->images)) {
                foreach ($review->images as $img) {
                    $inUseFilenames->push($extractFilename($img));
                }
            }
        }

        // Unique filenames only
        $inUseFilenames = $inUseFilenames->unique()->values();

        $media = collect($files)->map(function ($path) use ($inUseFilenames) {
            $filename = basename($path);
            $url = Storage::disk(MediaDisk::name())->url($path);

            return [
                'filename' => $filename,
                'url' => $url,
                'size' => Storage::disk(MediaDisk::name())->size($path),
                'last_modified' => Storage::disk(MediaDisk::name())->lastModified($path),
                'in_use' => $inUseFilenames->contains($filename),
            ];
        })->sortByDesc('last_modified')->values();

        return $this->success($media);
    }

    public function deleteMedia(Request $request): JsonResponse
    {
        $request->validate([
            'filename' => ['required', 'string'],
        ]);

        $path = 'media/'.$request->filename;

        if (Storage::disk(MediaDisk::name())->exists($path)) {
            Storage::disk(MediaDisk::name())->delete($path);

            return $this->success(null, 'ลบไฟล์สำเร็จ');
        }

        return $this->error('ไม่พบไฟล์ที่ต้องการลบ', 404);
    }

    // ─── Hero Slides ──────────────────────────────────────────

    public function publicHeroSlides(): JsonResponse
    {
        $slides = HeroSlide::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'image_url', 'alt_text']);

        return $this->success($slides);
    }

    public function heroSlides(): JsonResponse
    {
        $slides = HeroSlide::orderBy('sort_order')->get();

        return $this->success($slides);
    }

    public function storeHeroSlide(Request $request): JsonResponse
    {
        $request->validate([
            'image_url' => ['required', 'string', 'url'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $maxOrder = HeroSlide::max('sort_order') ?? -1;

        $slide = HeroSlide::create([
            'image_url' => $request->image_url,
            'alt_text' => $request->alt_text ?? 'ลุยเลเขา',
            'sort_order' => $maxOrder + 1,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return $this->success($slide, 'เพิ่มภาพสไลด์สำเร็จ');
    }

    public function updateHeroSlide(Request $request, int $id): JsonResponse
    {
        $slide = HeroSlide::findOrFail($id);

        $request->validate([
            'image_url' => ['sometimes', 'string', 'url'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $slide->update($request->only(['image_url', 'alt_text', 'is_active']));

        return $this->success($slide, 'อัปเดตภาพสไลด์สำเร็จ');
    }

    public function deleteHeroSlide(int $id): JsonResponse
    {
        $slide = HeroSlide::findOrFail($id);
        $slide->delete();

        return $this->success(null, 'ลบภาพสไลด์สำเร็จ');
    }

    public function reorderHeroSlides(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        foreach ($request->ids as $order => $id) {
            HeroSlide::where('id', $id)->update(['sort_order' => $order]);
        }

        return $this->success(null, 'จัดเรียงสไลด์สำเร็จ');
    }

    // ──────────────────────────────────────────────────────────────
    // Urgent-trips popup — ป๊อปอัพทริปด่วนหน้าเว็บ (flash sale + ที่นั่งใกล้เต็ม)

    public function urgentPopupSettings(): JsonResponse
    {
        return $this->success(UrgentPopupSettings::get());
    }

    public function updateUrgentPopupSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'show_flash_sale' => ['required', 'boolean'],
            'show_almost_full' => ['required', 'boolean'],
            'seat_threshold' => ['required', 'integer', 'min:1', 'max:20'],
            'title' => ['nullable', 'string', 'max:120'],
        ]);

        Setting::put(UrgentPopupSettings::KEY, $data);

        return $this->success(UrgentPopupSettings::get(), 'บันทึกการตั้งค่าป๊อปอัพแล้ว');
    }

    // ──────────────────────────────────────────────────────────────
    // Gallery — แกลเลอรีภาพประทับใจ (รูปที่แอดมินคัดเลือกเอง โชว์หน้า /gallery)

    public function publicGallery(): JsonResponse
    {
        $images = GalleryImage::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'image_url', 'caption', 'location']);

        return $this->success($images);
    }

    public function galleryImages(): JsonResponse
    {
        $images = GalleryImage::orderBy('sort_order')->get();

        return $this->success($images);
    }

    public function storeGalleryImage(Request $request): JsonResponse
    {
        $request->validate([
            'image_url' => ['required', 'string', 'url'],
            'caption' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $maxOrder = GalleryImage::max('sort_order') ?? -1;

        $image = GalleryImage::create([
            'image_url' => $request->image_url,
            'caption' => $request->caption,
            'location' => $request->location,
            'sort_order' => $maxOrder + 1,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return $this->success($image, 'เพิ่มภาพเข้าแกลเลอรีสำเร็จ');
    }

    public function updateGalleryImage(Request $request, int $id): JsonResponse
    {
        $image = GalleryImage::findOrFail($id);

        $request->validate([
            'image_url' => ['sometimes', 'string', 'url'],
            'caption' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $image->update($request->only(['image_url', 'caption', 'location', 'is_active']));

        return $this->success($image, 'อัปเดตภาพสำเร็จ');
    }

    public function deleteGalleryImage(int $id): JsonResponse
    {
        $image = GalleryImage::findOrFail($id);
        $image->delete();

        return $this->success(null, 'ลบภาพออกจากแกลเลอรีสำเร็จ');
    }

    public function reorderGalleryImages(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        foreach ($request->ids as $order => $id) {
            GalleryImage::where('id', $id)->update(['sort_order' => $order]);
        }

        return $this->success(null, 'จัดเรียงภาพสำเร็จ');
    }

    // ──────────────────────────────────────────────────────────────
    // Slip OCR Review
    // ──────────────────────────────────────────────────────────────

    /**
     * POST /admin/bookings/{ref}/slip/approve
     * อนุมัติสลิปด้วยตนเอง (main slip หรือ balance slip)
     */
    public function approveSlip(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate([
            'slip_type' => ['nullable', 'in:main,balance,installment'],
            'installment_no' => ['nullable', 'integer', 'min:1'],
        ]);

        $type = $data['slip_type'] ?? 'main';
        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        if ($type === 'installment') {
            $no = $data['installment_no'] ?? null;
            if (! $no) {
                return $this->error('ระบุหมายเลขงวด', 422);
            }

            $installment = InstallmentPayment::where('booking_id', $booking->id)
                ->where('installment_no', $no)
                ->firstOrFail();

            $installment->update(['slip_ocr_status' => SlipOcrService::STATUS_APPROVED]);

            return $this->success(null, "อนุมัติสลิปงวดที่ {$no} สำเร็จ");
        }

        $col = $type === 'balance' ? 'balance_slip_ocr_status' : 'slip_ocr_status';
        $booking->update([$col => SlipOcrService::STATUS_APPROVED]);

        // ถ้าเป็นสลิปหลักของการจองที่ถูก "กันไว้รอตรวจ" (ยอดไม่ตรงตอนชำระ) — การอนุมัติ
        // คือการยืนยันการจองจริง: confirm + ส่งอีเมล/SMS ยืนยัน + broadcast
        if ($type === 'main' && $booking->status === 'pending') {
            $this->confirmHeldBooking($booking);

            return $this->success(null, 'อนุมัติและยืนยันการจองสำเร็จ');
        }

        SmartNotification::send(
            $booking->user_id,
            'slip_approved',
            'สลิปได้รับการอนุมัติแล้ว',
            "ทีมงานตรวจสอบและอนุมัติการชำระเงินของเลขการจอง {$booking->booking_ref} แล้ว",
            ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
        );

        return $this->success(null, 'อนุมัติสลิปสำเร็จ');
    }

    /**
     * ยืนยันการจองที่ถูกกันไว้รอตรวจ (status = pending + ส่งสลิปแล้ว) หลังแอดมินอนุมัติ
     * — เดินตาม tail เดียวกับตอนชำระเงินสำเร็จ: confirm ตามชนิดการชำระ + แจ้งลูกค้า
     */
    private function confirmHeldBooking(Booking $booking): void
    {
        $type = $booking->payment_type ?: 'full';
        $method = $booking->payment_method ?: 'promptpay';
        $ref = $booking->payment_ref ?: ('PAY-'.strtoupper(uniqid()));
        $isSplit = $booking->splitShares()->exists();

        $amount = match ($type) {
            'installment' => (float) optional(
                $booking->installmentPayments()->where('installment_no', 1)->first()
            )->amount,
            'deposit' => (float) $booking->deposit_amount,
            default => (float) $booking->total_amount,
        };

        $booking = $this->bookingService->confirmBooking($booking->fresh(), $method, $ref, $amount ?: null);
        $booking = $booking->fresh()->load(['seats', 'schedule.trip', 'passengers']);

        try {
            foreach ($booking->seats as $seat) {
                broadcast(new SeatBooked($booking->schedule_id, $seat->seat_id, $booking->schedule->available_seats));
            }
            broadcast(new PaymentConfirmed(
                $booking->user_id,
                $booking->booking_ref,
                'confirmed',
                $booking->seats->pluck('seat_id')->toArray(),
            ));
        } catch (\Exception $e) {
            \Log::warning('confirmHeldBooking broadcast failed: '.$e->getMessage());
        }

        $sms = app(SmsService::class);

        if ($type === 'deposit') {
            $this->mailService->sendDepositPaidEmail($booking);
            if (! $isSplit) {
                $sms->sendDepositPaid($booking);
            }
            $balanceDueText = ThaiDate::full($booking->balance_due_at);
            SmartNotification::send(
                $booking->user_id,
                $isSplit ? 'split_started' : 'deposit_paid',
                $isSplit ? 'รับชำระส่วนของคุณแล้ว' : 'รับชำระเงินมัดจำแล้ว',
                $isSplit
                    ? "ทีมงานยืนยันส่วนของคุณสำหรับเลขการจอง {$booking->booking_ref} แล้ว แบ่งยอดที่เหลือให้เพื่อนช่วยจ่ายได้เลย"
                    : "รับชำระมัดจำเลขการจอง {$booking->booking_ref} แล้ว กรุณาชำระยอดส่วนที่เหลือภายในวันที่ {$balanceDueText}",
                ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
            );

            return;
        }

        $variant = $type === 'installment' ? 'installment' : 'full';
        $this->mailService->sendPaymentConfirmedEmail($booking, $variant);
        $sms->sendPaymentConfirmed($booking, $variant);
        SmartNotification::send(
            $booking->user_id,
            'payment_confirmed',
            'ยืนยันการชำระเงินแล้ว',
            "ทีมงานยืนยันการชำระเงินของเลขการจอง {$booking->booking_ref} แล้ว ที่นั่งของคุณได้รับการยืนยัน",
            ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
        );
    }

    /**
     * POST /admin/bookings/{ref}/slip/reject
     * ปฏิเสธสลิป — แจ้งลูกค้าให้อัพโหลดใหม่
     */
    public function rejectSlip(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate([
            'slip_type' => ['nullable', 'in:main,balance,installment'],
            'installment_no' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $type = $data['slip_type'] ?? 'main';
        $reason = $data['reason'] ?? 'สลิปไม่ถูกต้อง';
        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        if ($type === 'installment') {
            $no = $data['installment_no'] ?? null;
            if (! $no) {
                return $this->error('ระบุหมายเลขงวด', 422);
            }

            $installment = InstallmentPayment::where('booking_id', $booking->id)
                ->where('installment_no', $no)
                ->firstOrFail();

            $installment->update(['slip_ocr_status' => SlipOcrService::STATUS_REJECTED]);

            SmartNotification::send(
                $booking->user_id,
                'slip_rejected',
                'กรุณาตรวจสอบสลิปการชำระเงิน',
                "สลิปงวดที่ {$no} ของเลขการจอง {$booking->booking_ref}: {$reason} กรุณาอัพโหลดสลิปใหม่",
                ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
            );

            return $this->success(null, "ปฏิเสธสลิปงวดที่ {$no} สำเร็จ");
        }

        $col = $type === 'balance' ? 'balance_slip_ocr_status' : 'slip_ocr_status';
        $booking->update([$col => SlipOcrService::STATUS_REJECTED]);

        SmartNotification::send(
            $booking->user_id,
            'slip_rejected',
            'กรุณาตรวจสอบสลิปการชำระเงิน',
            "สลิปของเลขการจอง {$booking->booking_ref}: {$reason} กรุณาอัพโหลดสลิปใหม่",
            ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
        );

        return $this->success(null, 'ปฏิเสธสลิปสำเร็จ');
    }

    /**
     * POST /admin/bookings/{ref}/slip/reverify
     * Re-run OCR ใหม่ (กรณีอัพโหลดสลิปใหม่แล้ว)
     */
    public function reverifySlip(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate([
            'slip_type' => ['nullable', 'in:main,balance,installment'],
            'installment_no' => ['nullable', 'integer', 'min:1'],
        ]);

        $type = $data['slip_type'] ?? 'main';
        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        if ($type === 'installment') {
            $no = $data['installment_no'] ?? null;
            if (! $no) {
                return $this->error('ระบุหมายเลขงวด', 422);
            }

            $installment = InstallmentPayment::where('booking_id', $booking->id)
                ->where('installment_no', $no)
                ->firstOrFail();

            if (! $installment->slip_path) {
                return $this->error('ไม่มีสลิปในระบบ', 422);
            }

            $installment->update(['slip_ocr_status' => SlipOcrService::STATUS_PENDING]);
            VerifySlipJob::dispatch('installment', $installment->id, $installment->slip_path, (float) $installment->amount);

            return $this->success(null, 'ส่งคำขอตรวจสอบสลิปใหม่แล้ว');
        }

        if ($type === 'balance') {
            if (! $booking->balance_slip_path) {
                return $this->error('ไม่มีสลิปในระบบ', 422);
            }
            $booking->update(['balance_slip_ocr_status' => SlipOcrService::STATUS_PENDING]);
            VerifySlipJob::dispatch('balance', $booking->id, $booking->balance_slip_path, (float) $booking->balance_amount);
        } else {
            if (! $booking->slip_path) {
                return $this->error('ไม่มีสลิปในระบบ', 422);
            }
            $booking->update(['slip_ocr_status' => SlipOcrService::STATUS_PENDING]);
            $amount = $booking->payment_type === 'deposit' ? (float) $booking->deposit_amount : (float) $booking->total_amount;
            VerifySlipJob::dispatch('booking', $booking->id, $booking->slip_path, $amount);
        }

        return $this->success(null, 'ส่งคำขอตรวจสอบสลิปใหม่แล้ว');
    }
}
