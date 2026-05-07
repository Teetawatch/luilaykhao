<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreScheduleRequest;
use App\Http\Requests\Admin\StoreTripRequest;
use App\Http\Requests\Admin\StoreVehicleRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\SchedulePickupPointResource;
use App\Http\Resources\TripResource;
use App\Http\Resources\TripScheduleResource;
use App\Http\Resources\VehicleResource;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\InstallmentPayment;
use App\Models\Review;
use App\Models\SchedulePickupPoint;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePickupPoint;
use App\Models\BookingSeat;
use App\Services\BookingService;
use App\Services\MailService;
use App\Services\SmsService;
use App\Traits\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class AdminController extends Controller
{
    use ApiResponse;

    public function __construct(
        private BookingService $bookingService,
        private MailService $mailService,
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
        $monthlyInst = \App\Models\InstallmentPayment::where('status', 'paid')
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
                    \App\Models\InstallmentPayment::where('status', 'paid')
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
                $q->where('title', 'like', "%{$request->search}%")
                    ->orWhere('location', 'like', "%{$request->search}%");
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
        $data = $request->validated();
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
        $trip->update($request->validated());

        return $this->success(new TripResource($trip->fresh()), 'อัปเดตทริปสำเร็จ');
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

    // ─── Schedules ────────────────────────────────────────────

    public function schedules(Request $request): JsonResponse
    {
        $query = TripSchedule::with(['trip', 'vehicle', 'pickupPoints']);

        if ($request->filled('trip_id')) {
            $query->where('trip_id', $request->trip_id);
        }
        if ($request->filled('search')) {
            $query->whereHas('trip', fn ($q) => $q->where('title', 'like', "%{$request->search}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('upcoming')) {
            $query->where('departure_date', '>=', now()->startOfDay());
        }

        $schedules = $query->orderByDesc('departure_date')->paginate($request->get('per_page', 15));
        $schedules->getCollection()->each->syncBookedSeats();

        return $this->paginated($schedules->through(fn ($s) => new TripScheduleResource($s)));
    }

    public function storeSchedule(StoreScheduleRequest $request): JsonResponse
    {
        $schedule = TripSchedule::create($request->validated());

        return $this->success(
            new TripScheduleResource($schedule->load('trip', 'vehicle')),
            'สร้างรอบเดินทางสำเร็จ',
            201,
        );
    }

    public function updateSchedule(Request $request, int $id): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($id);

        $validated = $request->validate([
            'departure_date' => ['sometimes', 'date'],
            'return_date' => ['sometimes', 'date'],
            'total_seats' => ['sometimes', 'integer', 'min:1'],
            'transport_type' => ['sometimes', 'in:van,boat,bus'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'status' => ['sometimes', 'in:open,closed,full,cancelled'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'installment_enabled' => ['nullable', 'boolean'],
            'installment_count' => ['nullable', 'integer', 'min:2', 'max:12'],
            'installment_interval_days' => ['nullable', 'integer', 'min:1'],
            'join_trip_enabled' => ['nullable', 'boolean'],
            'join_trip_price' => ['nullable', 'numeric', 'min:0'],
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
        $pickupMap = [];
        foreach ($source->pickupPoints as $sPoint) {
            $tPoint = $target->pickupPoints->where('pickup_location', $sPoint->pickup_location)->first();
            if ($tPoint) {
                $pickupMap[$sPoint->id] = $tPoint->id;
            }
        }

        $seatMoves = $bookings
            ->flatMap(fn ($booking) => $this->seatMovesForBooking($booking, $selectedPassengerIds, $seatAssignments))
            ->values();

        $seatIdsToMove = $seatMoves
            ->pluck('target_seat_id')
            ->filter()
            ->values();

        $duplicateSeatIds = $seatIdsToMove
            ->duplicates()
            ->unique()
            ->values();

        if ($duplicateSeatIds->isNotEmpty()) {
            return $this->error('เลือกที่นั่งปลายทางซ้ำ: ' . $duplicateSeatIds->join(', '), 422);
        }

        $seatIdsToMove = $seatIdsToMove->unique()->values();

        if ($seatIdsToMove->isNotEmpty()) {
            $movingSeatRowIds = $seatMoves
                ->map(fn ($move) => $move['seat']->id)
                ->unique()
                ->values();

            $occupiedSeatQuery = BookingSeat::where('schedule_id', $target->id)
                ->whereIn('seat_id', $seatIdsToMove);

            if ($sameSchedule) {
                $occupiedSeatQuery->whereNotIn('id', $movingSeatRowIds);
            }

            $occupiedSeatIds = $occupiedSeatQuery->pluck('seat_id')->values();

            if ($occupiedSeatIds->isNotEmpty()) {
                return $this->error('ที่นั่ง ' . $occupiedSeatIds->join(', ') . ' ในรอบปลายทางถูกจองแล้ว กรุณาเลือกปลายทางอื่นหรือแก้ผังที่นั่งก่อน', 422);
            }
        }

        DB::transaction(function () use ($source, $target, $sameSchedule, $bookings, $pickupMap, $selectedPassengerIds, $seatAssignments) {
            foreach ($bookings as $booking) {
                $selectedInBooking = $booking->passengers
                    ->whereIn('id', $selectedPassengerIds->all())
                    ->values();
                $seatMoves = $this->seatMovesForBooking($booking, $selectedPassengerIds, $seatAssignments);

                if ($sameSchedule) {
                    $seatMoves->each(fn ($move) => $move['seat']->update([
                        'seat_id' => $move['target_seat_id'],
                    ]));

                    continue;
                }

                if ($selectedInBooking->count() === $booking->passengers->count()) {
                    $updateData = ['schedule_id' => $target->id];

                    // Update pickup point if mapped
                    if ($booking->pickup_point_id && isset($pickupMap[$booking->pickup_point_id])) {
                        $updateData['pickup_point_id'] = $pickupMap[$booking->pickup_point_id];
                    }

                    $booking->update($updateData);

                    $seatMoves->each(fn ($move) => $move['seat']->update([
                        'schedule_id' => $target->id,
                        'seat_id' => $move['target_seat_id'],
                    ]));
                } else {
                    $newBooking = $this->splitBookingForMove($booking, $selectedInBooking, $target, $pickupMap);

                    $selectedInBooking
                        ->each(fn ($passenger) => $passenger->update(['booking_id' => $newBooking->id]));

                    $seatMoves
                        ->each(fn ($move) => $move['seat']->update([
                            'booking_id' => $newBooking->id,
                            'schedule_id' => $target->id,
                            'seat_id' => $move['target_seat_id'],
                        ]));
                }
            }

            $source->syncBookedSeats();
            $target->syncBookedSeats();
        });

        return $this->success(null, "ย้ายผู้โดยสาร $totalPassengers ท่าน จาก $bookingsCount รายการจอง ไปยังรอบเดินทางวันที่ " . $target->departure_date->format('d/m/Y') . " สำเร็จ");
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

    private function splitBookingForMove(Booking $booking, $selectedPassengers, TripSchedule $target, array $pickupMap): Booking
    {
        $originalPassengerCount = max(1, $booking->passengers->count());
        $selectedCount = max(1, $selectedPassengers->count());
        $ratio = $selectedCount / $originalPassengerCount;

        $targetPickupPointId = $booking->pickup_point_id && isset($pickupMap[$booking->pickup_point_id])
            ? $pickupMap[$booking->pickup_point_id]
            : $booking->pickup_point_id;

        $newBooking = $booking->replicate([
            'booking_ref',
            'qr_code',
            'created_at',
            'updated_at',
            'checked_in',
            'checked_in_at',
        ]);

        $newBooking->fill([
            'booking_ref' => Booking::generateRef(),
            'qr_code' => Booking::generateQrCode(),
            'schedule_id' => $target->id,
            'pickup_point_id' => $targetPickupPointId,
            'is_group' => $selectedCount > 1,
            'total_amount' => round(((float) $booking->total_amount) * $ratio, 2),
            'paid_amount' => round(((float) $booking->paid_amount) * $ratio, 2),
            'discount_amount' => round(((float) $booking->discount_amount) * $ratio, 2),
        ]);
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
        $schedule = TripSchedule::with('trip')->findOrFail($id);

        if (! Schema::hasTable('schedule_staff_assignments')) {
            return $this->success([
                'schedule' => [
                    'id' => $schedule->id,
                    'trip_title' => $schedule->trip?->title,
                    'departure_date' => $schedule->departure_date?->toDateString(),
                    'return_date' => $schedule->return_date?->toDateString(),
                    'status' => $schedule->status,
                ],
                'staff' => [],
            ]);
        }

        $schedule->load('staff');

        return $this->success([
            'schedule' => [
                'id' => $schedule->id,
                'trip_title' => $schedule->trip?->title,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'return_date' => $schedule->return_date?->toDateString(),
                'status' => $schedule->status,
            ],
            'staff' => $schedule->staff->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar_url' => $user->avatar_url,
                'assigned_at' => $user->pivot?->created_at,
            ])->values(),
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

        $staffRoleExists = Role::query()
            ->where('name', 'staff')
            ->where('guard_name', config('auth.defaults.guard', 'web'))
            ->exists();

        if (! $staffRoleExists && $staffIds->isNotEmpty()) {
            return $this->error('ยังไม่ได้ตั้งค่า role staff ในระบบ', 422);
        }

        if ($staffIds->isNotEmpty()) {
            $validStaffIds = User::role('staff')->whereIn('id', $staffIds)->pluck('id');

            if ($validStaffIds->count() !== $staffIds->count()) {
                return $this->error('พบผู้ใช้ที่ไม่ได้เป็นสิทธิ์ staff', 422);
            }
        }

        $syncPayload = $staffIds
            ->mapWithKeys(fn ($staffId) => [(int) $staffId => ['assigned_by' => $request->user()->id]])
            ->all();

        $schedule->staff()->sync($syncPayload);
        $schedule->load('staff');

        return $this->success($schedule->staff->map(fn ($user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar_url' => $user->avatar_url,
            'assigned_at' => $user->pivot?->created_at,
        ])->values(), 'อัปเดตรายชื่อสตาฟประจำรอบสำเร็จ');
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
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('booking_ref', 'like', "%{$request->search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$request->search}%")
                        ->orWhere('email', 'like', "%{$request->search}%")
                        ->orWhere('phone', 'like', "%{$request->search}%"))
                    ->orWhereHas('passengers', fn ($p) => $p->where('name', 'like', "%{$request->search}%")
                        ->orWhere('phone', 'like', "%{$request->search}%"))
                    ->orWhereHas('schedule.trip', fn ($trip) => $trip->where('title', 'like', "%{$request->search}%"));
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
            'passengers',
            'seats',
            'installmentPayments',
            'pickupPoint',
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

    public function updateBooking(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,confirmed,cancelled,refunded'],
            'schedule_id' => ['nullable', 'exists:trip_schedules,id'],
            'pickup_region' => ['nullable', 'string', 'max:100'],
            'pickup_point_id' => ['nullable', 'exists:schedule_pickup_points,id'],
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
            'payment_type' => ['nullable', 'in:full,installment'],
            'installment_count' => ['nullable', 'integer', 'min:1', 'max:12'],
            'installment_interval_days' => ['nullable', 'integer', 'min:1', 'max:365'],
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
        ]);

        $booking = Booking::with(['user', 'passengers', 'seats', 'installmentPayments', 'schedule'])
            ->where('booking_ref', $ref)
            ->firstOrFail();

        DB::transaction(function () use ($request, $data, $booking) {
            $oldSchedule = $booking->schedule;

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
            if (array_key_exists('checked_in', $data)) {
                $bookingUpdates['checked_in'] = (bool) $data['checked_in'];
                if (! $bookingUpdates['checked_in']) {
                    $bookingUpdates['checked_in_at'] = null;
                } elseif (empty($bookingUpdates['checked_in_at']) && ! $booking->checked_in_at) {
                    $bookingUpdates['checked_in_at'] = now();
                }
            }

            if (($data['delete_slip'] ?? false) && $booking->slip_path) {
                Storage::disk('public')->delete($booking->slip_path);
                $bookingUpdates['slip_path'] = null;
            }
            if ($request->hasFile('slip_image')) {
                if ($booking->slip_path) {
                    Storage::disk('public')->delete($booking->slip_path);
                }
                $bookingUpdates['slip_path'] = $request->file('slip_image')->store('slips/' . date('Y/m'), 'public');
            }

            if ($bookingUpdates) {
                $booking->update($bookingUpdates);
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

            if (array_key_exists('seat_ids', $data)) {
                $booking->seats()->delete();
                if (! ($booking->fresh()->is_join_trip)) {
                    foreach (array_values(array_filter($data['seat_ids'] ?? [])) as $index => $seatId) {
                        $booking->seats()->create([
                            'schedule_id' => $booking->schedule_id,
                            'seat_id' => $seatId,
                            'passenger_name' => $booking->passengers()->skip($index)->first()?->name,
                        ]);
                    }
                }
            }

            if (($data['payment_type'] ?? $booking->payment_type) === 'full') {
                if (array_key_exists('payment_type', $data)) {
                    foreach ($booking->installmentPayments as $payment) {
                        if ($payment->slip_path) {
                            Storage::disk('public')->delete($payment->slip_path);
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
                        Storage::disk('public')->delete($installment->slip_path);
                        $installment->update(['slip_path' => null]);
                    }

                    $file = $request->file("installments.$index.slip_image");
                    if ($file) {
                        if ($installment->slip_path) {
                            Storage::disk('public')->delete($installment->slip_path);
                        }
                        $installment->update([
                            'slip_path' => $file->store('slips/' . date('Y/m'), 'public'),
                        ]);
                    }

                    $keptInstallmentIds[] = $installment->id;
                }

                $removedPayments = $booking->installmentPayments()->whereNotIn('id', $keptInstallmentIds ?: [0])->get();
                foreach ($removedPayments as $payment) {
                    if ($payment->slip_path) {
                        Storage::disk('public')->delete($payment->slip_path);
                    }
                    $payment->delete();
                }
            }

            $booking->fresh(['schedule'])->schedule?->syncBookedSeats();
            if ($oldSchedule && $oldSchedule->id !== $booking->schedule_id) {
                $oldSchedule->syncBookedSeats();
            }
        });

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
            'passengers.*.blood_group' => ['nullable', 'string', 'max:10'],
            'passengers.*.allergies' => ['nullable', 'string'],
            'passengers.*.health_notes' => ['nullable', 'string'],
            'passengers.*.emergency_contact' => ['nullable', 'string', 'max:255'],
            'passengers.*.emergency_phone' => ['nullable', 'string', 'max:20'],
            'passengers.*.dive_cert_level' => ['nullable', 'string', 'max:255'],
            'passengers.*.cert_number' => ['nullable', 'string', 'max:255'],
            'passengers.*.weight' => ['nullable', 'numeric', 'min:0'],
            'passengers.*.halal_food' => ['nullable', 'boolean'],
            'seat_ids' => ['nullable', 'array'],
            'seat_ids.*' => ['nullable', 'string', 'max:30'],
            'pickup_point_id' => ['nullable', 'exists:schedule_pickup_points,id'],
            'pickup_region' => ['nullable', 'string', 'max:80'],
            'is_join_trip' => ['nullable', 'boolean'],
            'status' => ['required', 'in:pending,confirmed'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'payment_type' => ['nullable', 'in:full,installment'],
            'installment_count' => ['nullable', 'integer', 'min:2', 'max:6'],
            'slip_image' => ['nullable', 'image', 'max:5120'],
            'transfer_date' => ['nullable', 'date'],
            'transfer_time' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'send_email' => ['nullable', 'boolean'],
        ]);

        $schedule = TripSchedule::with(['trip', 'pickupPoints'])->findOrFail($request->schedule_id);
        $schedule->syncBookedSeats();

        $fullName = trim($request->input('customer_name') ?: trim(($request->input('name') ?? '') . ' ' . ($request->input('surname') ?? '')));
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

        if ($isJoinTrip && ! $schedule->join_trip_enabled) {
            return $this->error('รอบเดินทางนี้ยังไม่ได้เปิดจอยทริป', 422);
        }

        if ($paymentType === 'installment' && ($isJoinTrip || ! $schedule->installment_enabled)) {
            return $this->error('รอบเดินทางนี้ไม่รองรับการผ่อนชำระ', 422);
        }

        if (! $isJoinTrip && $schedule->available_seats < $participantCount) {
            return $this->error('ที่นั่งไม่เพียงพอสำหรับรอบเดินทางนี้', 422);
        }

        $seatIds = collect($request->input('seat_ids', []))->filter()->values();
        if (! $isJoinTrip && $seatIds->isNotEmpty()) {
            if ($seatIds->count() !== $participantCount) {
                return $this->error('จำนวนที่นั่งต้องเท่ากับจำนวนผู้เดินทาง', 422);
            }

            $occupiedSeatIds = BookingSeat::where('schedule_id', $schedule->id)
                ->whereIn('seat_id', $seatIds)
                ->pluck('seat_id');

            if ($occupiedSeatIds->isNotEmpty()) {
                return $this->error('ที่นั่ง ' . $occupiedSeatIds->join(', ') . ' ถูกจองแล้ว', 422);
            }
        }

        $pickupPoint = null;
        if (! $isJoinTrip && $request->filled('pickup_point_id')) {
            $pickupPoint = $schedule->pickupPoints->firstWhere('id', (int) $request->pickup_point_id);
            if (! $pickupPoint) {
                return $this->error('จุดรับไม่อยู่ในรอบเดินทางนี้', 422);
            }
        }

        $email = $request->input('email');
        $user = User::when($email, fn ($query) => $query->where('email', $email))
            ->when(! $email, fn ($query) => $query->where('phone', $request->phone))
            ->first();

        if (!$user) {
            $user = User::create([
                'name' => $fullName,
                'phone' => $request->phone,
                'email' => $email ?: 'manual_' . time() . '_' . Str::random(4) . '@luilaykhao.com',
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

        $pricePerPerson = $isJoinTrip
            ? ($schedule->join_trip_price ?? $schedule->effective_price)
            : ($pickupPoint?->price ?? $schedule->effective_price);
        $totalAmount = $pricePerPerson * $participantCount;
        $installmentCount = null;
        $installmentIntervalDays = null;
        $paidAmount = $isPaid ? $totalAmount : 0;

        if ($paymentType === 'installment') {
            $maxAllowed = min((int) $schedule->installment_count, 6);
            $installmentCount = (int) ($request->input('installment_count') ?: $schedule->installment_count);
            if ($installmentCount < 2 || $installmentCount > $maxAllowed) {
                return $this->error("จำนวนงวดต้องอยู่ระหว่าง 2-{$maxAllowed} งวด", 422);
            }
            $installmentIntervalDays = (int) $schedule->installment_interval_days;
            $paidAmount = $isPaid ? round($totalAmount / $installmentCount, 2) : 0;
        }

        $transferDt = $this->resolveManualTransferDatetime($request);
        if ($isPaid && (! $request->hasFile('slip_image') || ! $transferDt)) {
            return $this->error('กรุณาแนบสลิปและระบุวันเวลาที่โอนเงิน', 422);
        }
        $slipPath = null;
        if ($request->hasFile('slip_image')) {
            $slipPath = $request->file('slip_image')->store('slips/' . date('Y/m'), 'public');
        }
        $paymentRef = $isPaid ? 'PAY-MANUAL-' . strtoupper(uniqid()) : null;

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $request->schedule_id,
            'pickup_region' => $pickupPoint?->region ?: $request->input('pickup_region'),
            'pickup_point_id' => $pickupPoint?->id,
            'is_group' => $participantCount > 1,
            'status' => $request->status,
            'total_amount' => $totalAmount,
            'paid_amount' => $paidAmount,
            'payment_type' => $paymentType,
            'installment_count' => $installmentCount,
            'installment_interval_days' => $installmentIntervalDays,
            'payment_method' => $request->input('payment_method', 'promptpay'),
            'payment_ref' => $paymentRef,
            'paid_at' => $isPaid ? now() : null,
            'slip_path' => $slipPath,
            'transfer_datetime' => $transferDt,
            'qr_code' => Booking::generateQrCode(),
            'is_join_trip' => $isJoinTrip,
        ]);

        $passengerModels = $passengers->map(function ($passenger) use ($booking) {
            return BookingPassenger::create([
                'booking_id' => $booking->id,
                'title' => $passenger['title'] ?? '',
                'name' => $passenger['name'],
                'nickname' => $passenger['nickname'] ?? null,
                'phone' => $passenger['phone'] ?? null,
                'email' => $passenger['email'] ?? null,
                'id_card' => $passenger['id_card'] ?? null,
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
                    'schedule_id' => $schedule->id,
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
                    'due_date' => now()->copy()->addDays(($i - 1) * $installmentIntervalDays)->toDateString(),
                    'status' => $i === 1 ? 'paid' : 'pending',
                    'payment_method' => $i === 1 ? $booking->payment_method : null,
                    'payment_ref' => $i === 1 ? $paymentRef : null,
                    'paid_at' => $i === 1 ? $booking->paid_at : null,
                    'slip_path' => $i === 1 ? $slipPath : null,
                    'transfer_datetime' => $i === 1 ? $transferDt : null,
                ]);
            }
        }

        $schedule->syncBookedSeats();

        $booking->load(['schedule.trip', 'schedule.vehicle', 'pickupPoint', 'user', 'passengers', 'seats', 'installmentPayments']);

        if ($request->boolean('send_email', true) && $user->email && ! str_starts_with($user->email, 'manual_')) {
            app(MailService::class)->sendBookingCreatedEmail($booking);
            if ($booking->status === 'confirmed') {
                app(MailService::class)->sendPaymentConfirmedEmail($booking, $paymentType);
            }
        }

        if ($booking->status === 'confirmed') {
            app(SmsService::class)->sendPaymentConfirmed($booking, $paymentType);
        }

        return $this->success(new BookingResource($booking), 'บันทึกการจองและส่งอีเมลสำเร็จ', 201);
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

        // 1. Delete associated files
        if ($booking->slip_path) {
            Storage::disk('public')->delete($booking->slip_path);
        }

        // Also delete slip for installment payments
        foreach ($booking->installmentPayments as $payment) {
            if ($payment->slip_path) {
                Storage::disk('public')->delete($payment->slip_path);
            }
        }

        // 2. Delete records
        $booking->seats()->delete();
        $booking->passengers()->delete();
        $booking->installmentPayments()->delete();
        $booking->delete();
        $schedule?->syncBookedSeats();

        return $this->success(null, 'ลบการจองสำเร็จ');
    }

    public function manifest(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->findOrFail($scheduleId);
        $schedule->syncBookedSeats(); // Auto-sync count when manifest is viewed

        $passengers = BookingPassenger::whereHas('booking', function ($q) use ($scheduleId) {
            $q->where('schedule_id', $scheduleId)
                ->whereIn('status', ['confirmed', 'pending']);
        })->with(['booking.seats', 'booking.pickupPoint', 'booking.user'])->get()->map(function($p) {
            $p->is_join_trip = $p->booking->is_join_trip ?? false;
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
        $query = Vehicle::withCount('schedules')->with('pickupPoints');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $vehicles = $query->orderBy('name')->paginate($request->get('per_page', 15));

        return $this->paginated($vehicles->through(fn ($v) => new VehicleResource($v)));
    }

    public function storeVehicle(StoreVehicleRequest $request): JsonResponse
    {
        $vehicle = Vehicle::create($request->validated());

        return $this->success(new VehicleResource($vehicle->load('pickupPoints')), 'สร้างยานพาหนะสำเร็จ', 201);
    }

    public function updateVehicle(StoreVehicleRequest $request, int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);
        $vehicle->update($request->validated());

        return $this->success(new VehicleResource($vehicle->fresh()->load('pickupPoints')), 'อัปเดตยานพาหนะสำเร็จ');
    }

    public function deleteVehicle(int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);

        $hasSchedules = $vehicle->schedules()
            ->where('departure_date', '>=', now())
            ->exists();

        if ($hasSchedules) {
            return $this->error('ไม่สามารถลบยานพาหนะที่มีรอบเดินทางอยู่', 422);
        }

        $vehicle->delete();

        return $this->success(null, 'ลบยานพาหนะสำเร็จ');
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

        if ($request->filled('role')) {
            $query->role($request->role);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
                    ->orWhere('phone', 'like', "%{$request->search}%");
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
        $staffRoleExists = Role::query()
            ->where('name', 'staff')
            ->where('guard_name', config('auth.defaults.guard', 'web'))
            ->exists();

        if (! $staffRoleExists) {
            $empty = User::query()
                ->whereRaw('1 = 0')
                ->paginate($request->get('per_page', 30));

            return $this->paginated($empty);
        }

        $hasAssignmentsTable = Schema::hasTable('schedule_staff_assignments');
        $hasReviewsTable = Schema::hasTable('staff_reviews');

        $query = User::role('staff');

        if ($hasAssignmentsTable) {
            $query->withCount('assignedSchedules');
        }

        if ($hasReviewsTable) {
            $query->withAvg('staffReviewsReceived as avg_staff_rating', 'rating');
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
                    ->orWhere('phone', 'like', "%{$request->search}%");
            });
        }

        $staff = $query->orderBy('name')->paginate($request->get('per_page', 30));

        return $this->paginated($staff->through(fn ($user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar_url' => $user->avatar_url,
            'assigned_schedules_count' => $user->assigned_schedules_count ?? 0,
            'avg_staff_rating' => $user->avg_staff_rating ? round((float) $user->avg_staff_rating, 2) : null,
        ]));
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

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'driver_pin_hash' => ! empty($validated['driver_pin'])
                ? Hash::make($validated['driver_pin'])
                : null,
        ]);

        $user->assignRole($validated['role']);

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
            $userData['driver_pin_hash'] = Hash::make($validated['driver_pin']);
        }

        $user->update($userData);

        if (isset($validated['role'])) {
            $user->syncRoles([$validated['role']]);
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
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:500'],
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

    public function updatePickupPoint(Request $request, int $scheduleId, int $pointId): JsonResponse
    {
        $point = SchedulePickupPoint::where('schedule_id', $scheduleId)->findOrFail($pointId);

        $validated = $request->validate([
            'region' => ['sometimes', 'string', 'max:50'],
            'region_label' => ['sometimes', 'string', 'max:100'],
            'pickup_location' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'map_url' => ['nullable', 'url', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:500'],
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

    // ─── Media Upload ─────────────────────────────────────────
    public function uploadMedia(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,webp,gif,mp4,mov,avi', 'max:51200'], // max 50MB
        ]);

        $file = $request->file('file');
        $filename = time().'_'.Str::random(8).'.'.$file->getClientOriginalExtension();

        $path = $file->storeAs('media', $filename, 'public');
        $url = Storage::disk('public')->url($path);

        return $this->success([
            'url' => $url,
            'filename' => $filename,
        ], 'อัปโหลดสื่อสำเร็จ');
    }

    public function listMedia(): JsonResponse
    {
        $files = Storage::disk('public')->files('media');

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
            $url = Storage::disk('public')->url($path);

            return [
                'filename' => $filename,
                'url' => $url,
                'size' => Storage::disk('public')->size($path),
                'last_modified' => Storage::disk('public')->lastModified($path),
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

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);

            return $this->success(null, 'ลบไฟล์สำเร็จ');
        }

        return $this->error('ไม่พบไฟล์ที่ต้องการลบ', 404);
    }
}
