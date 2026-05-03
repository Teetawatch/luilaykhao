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
use App\Traits\ApiResponse;
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
            'target_schedule_id' => ['required', 'exists:trip_schedules,id', 'different:source_schedule_id'],
        ]);

        $source = TripSchedule::with(['bookings.passengers', 'pickupPoints'])->findOrFail($request->source_schedule_id);
        $target = TripSchedule::with('pickupPoints')->findOrFail($request->target_schedule_id);

        $bookings = $source->bookings;
        $bookingsCount = $bookings->count();

        if ($bookingsCount === 0) {
            return $this->error('ไม่พบรายการจองในรอบต้นทาง', 422);
        }

        // Calculate total passengers being moved
        $totalPassengers = $bookings->sum(fn($b) => $b->passengers->count() ?: 1);

        // Check capacity if not join trip
        if (!$target->join_trip_enabled && $target->available_seats < $totalPassengers) {
            return $this->error("ที่นั่งในรอบปลายทางไม่เพียงพอ (ต้องการ $totalPassengers, ว่าง {$target->available_seats})", 422);
        }

        // Prepare pickup point mapping
        $pickupMap = [];
        foreach ($source->pickupPoints as $sPoint) {
            $tPoint = $target->pickupPoints->where('pickup_location', $sPoint->pickup_location)->first();
            if ($tPoint) {
                $pickupMap[$sPoint->id] = $tPoint->id;
            }
        }

        DB::transaction(function () use ($source, $target, $bookings, $totalPassengers, $pickupMap) {
            foreach ($bookings as $booking) {
                $updateData = ['schedule_id' => $target->id];
                
                // Update pickup point if mapped
                if ($booking->pickup_point_id && isset($pickupMap[$booking->pickup_point_id])) {
                    $updateData['pickup_point_id'] = $pickupMap[$booking->pickup_point_id];
                }

                $booking->update($updateData);

                // Update BookingSeats
                BookingSeat::where('booking_id', $booking->id)
                    ->update(['schedule_id' => $target->id]);
            }

            // Update booked_seats counts
            $source->decrement('booked_seats', $totalPassengers);
            $target->increment('booked_seats', $totalPassengers);
        });

        return $this->success(null, "ย้ายการจอง $bookingsCount รายการ ($totalPassengers ท่าน) ไปยังรอบเดินทางวันที่ " . $target->departure_date->format('d/m/Y') . " สำเร็จ");
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
        $query = Booking::with(['schedule.trip', 'schedule.pickupPoints', 'user', 'passengers', 'seats', 'installmentPayments', 'pickupPoint']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->date);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('booking_ref', 'like', "%{$request->search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$request->search}%")
                        ->orWhere('email', 'like', "%{$request->search}%"));
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
        $booking = Booking::with(['schedule.trip', 'schedule.pickupPoints', 'user', 'passengers', 'seats', 'installmentPayments', 'pickupPoint'])
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
            $booking->update(['status' => $request->status]);
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

    public function storeManualBooking(Request $request): JsonResponse
    {
        $request->validate([
            'schedule_id' => ['required', 'exists:trip_schedules,id'],
            'name' => ['required', 'string', 'max:255'],
            'surname' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'passenger_count' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:pending,confirmed'],
        ]);

        $schedule = TripSchedule::with('trip')->findOrFail($request->schedule_id);
        
        // If regular booking (not join trip), check seats
        if (!$schedule->join_trip_enabled && $schedule->available_seats < $request->passenger_count) {
             return $this->error('ที่นั่งไม่เพียงพอสำหรับรอบเดินทางนี้', 422);
        }

        $fullName = $request->name . ' ' . $request->surname;
        
        // Find user by phone, or create a placeholder if not exists
        $user = User::where('phone', $request->phone)->first();
        if (!$user) {
            $user = User::create([
                'name' => $fullName,
                'phone' => $request->phone,
                'email' => 'manual_' . time() . '_' . Str::random(4) . '@luilaykhao.com',
                'password' => Hash::make(Str::random(16)),
            ]);
            $user->assignRole('customer');
        }

        $totalAmount = ($schedule->join_trip_enabled ? ($schedule->join_trip_price ?? $schedule->effective_price) : $schedule->effective_price) * $request->passenger_count;

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $request->schedule_id,
            'status' => $request->status,
            'total_amount' => $totalAmount,
            'paid_amount' => $request->status === 'confirmed' ? $totalAmount : 0,
            'payment_type' => 'full',
            'payment_method' => 'manual',
            'paid_at' => $request->status === 'confirmed' ? now() : null,
            'qr_code' => Booking::generateQrCode(),
            'is_join_trip' => (bool) $schedule->join_trip_enabled,
        ]);

        // Create passengers
        for ($i = 0; $i < $request->passenger_count; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'title' => '',
                'name' => $i === 0 ? $fullName : "ผู้ติดตามคนที่ " . ($i + 1),
                'phone' => $i === 0 ? $request->phone : null,
            ]);
        }

        // Update booked seats if not join trip
        if (!$schedule->join_trip_enabled) {
            $schedule->increment('booked_seats', $request->passenger_count);
        }

        return $this->success(new BookingResource($booking->load(['schedule.trip', 'user', 'passengers'])), 'บันทึกการจองสำเร็จ', 201);
    }

    public function deleteBooking(string $ref): JsonResponse
    {
        $booking = Booking::with(['seats', 'schedule', 'installmentPayments'])->where('booking_ref', $ref)->firstOrFail();

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

        // 2. Restore seats if not join trip and booking was confirmed/pending
        if (!$booking->is_join_trip && in_array($booking->status, ['pending', 'confirmed'])) {
            $passengerCount = $booking->passengers()->count() ?: 1;
            $booking->schedule?->decrement('booked_seats', $passengerCount);
        }

        // 3. Delete records
        $booking->seats()->delete();
        $booking->passengers()->delete();
        $booking->installmentPayments()->delete();
        $booking->delete();

        return $this->success(null, 'ลบการจองสำเร็จ');
    }

    public function manifest(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->findOrFail($scheduleId);

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
