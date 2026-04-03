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
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePickupPoint;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    use ApiResponse;

    // ─── Dashboard Stats ──────────────────────────────────────

    public function dashboard(): JsonResponse
    {
        $totalTrips = Trip::count();
        $activeTrips = Trip::where('status', 'active')->count();

        $totalBookings = Booking::count();
        $pendingBookings = Booking::where('status', 'pending')->count();
        $confirmedBookings = Booking::where('status', 'confirmed')->count();
        $cancelledBookings = Booking::where('status', 'cancelled')->count();

        $totalRevenue = Booking::where('status', 'confirmed')->sum('paid_amount');
        $monthlyRevenue = Booking::where('status', 'confirmed')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('paid_amount');

        $totalCustomers = User::role('customer')->count();
        $totalVehicles = Vehicle::count();

        $upcomingSchedules = TripSchedule::where('departure_date', '>=', now())
            ->where('status', 'open')
            ->count();

        $recentBookings = Booking::with(['schedule.trip', 'user'])
            ->orderByDesc('created_at')
            ->take(5)
            ->get()
            ->map(fn($b) => new BookingResource($b));

        // Monthly revenue chart data (last 6 months)
        $revenueChart = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $revenueChart[] = [
                'month' => $date->format('M Y'),
                'revenue' => (float) Booking::where('status', 'confirmed')
                    ->whereMonth('created_at', $date->month)
                    ->whereYear('created_at', $date->year)
                    ->sum('paid_amount'),
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

        return $this->paginated($trips->through(fn($t) => new TripResource($t)));
    }

    public function storeTrip(StoreTripRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = Str::slug($data['title']) . '-' . Str::random(5);

        $trip = Trip::create($data);

        return $this->success(new TripResource($trip), 'สร้างทริปสำเร็จ', 201);
    }

    public function updateTrip(StoreTripRequest $request, int $id): JsonResponse
    {
        $trip = Trip::findOrFail($id);
        $trip->update($request->validated());

        return $this->success(new TripResource($trip->fresh()), 'อัปเดตทริปสำเร็จ');
    }

    public function deleteTrip(int $id): JsonResponse
    {
        $trip = Trip::findOrFail($id);

        // check if trip has any confirmed bookings
        $hasBookings = Booking::whereHas('schedule', fn($q) => $q->where('trip_id', $id))
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
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('upcoming')) {
            $query->where('departure_date', '>=', now());
        }

        $schedules = $query->orderByDesc('departure_date')->paginate($request->get('per_page', 15));

        return $this->paginated($schedules->through(fn($s) => new TripScheduleResource($s)));
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
        ]);

        $schedule->update($validated);

        return $this->success(
            new TripScheduleResource($schedule->fresh()->load('trip', 'vehicle')),
            'อัปเดตรอบเดินทางสำเร็จ',
        );
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

    // ─── Bookings ─────────────────────────────────────────────

    public function bookings(Request $request): JsonResponse
    {
        $query = Booking::with(['schedule.trip', 'user', 'passengers', 'seats']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->date);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('booking_ref', 'like', "%{$request->search}%")
                  ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$request->search}%")
                      ->orWhere('email', 'like', "%{$request->search}%"));
            });
        }

        $bookings = $query->orderByDesc('created_at')->paginate($request->get('per_page', 15));

        return $this->paginated($bookings->through(fn($b) => new BookingResource($b)));
    }

    public function showBooking(string $ref): JsonResponse
    {
        $booking = Booking::with(['schedule.trip', 'user', 'passengers', 'seats'])
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

        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        $updateData = ['status' => $request->status];
        if ($request->status === 'cancelled' || $request->status === 'refunded') {
            $updateData['cancellation_reason'] = $request->cancellation_reason;
            $updateData['cancelled_at'] = now();
        }

        $booking->update($updateData);

        return $this->success(new BookingResource($booking->fresh()), 'อัปเดตสถานะสำเร็จ');
    }

    public function manifest(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->findOrFail($scheduleId);

        $passengers = BookingPassenger::whereHas('booking', function ($q) use ($scheduleId) {
            $q->where('schedule_id', $scheduleId)
              ->whereIn('status', ['confirmed', 'pending']);
        })->with('booking.seats')->get();

        return $this->success([
            'schedule' => new TripScheduleResource($schedule),
            'passengers' => $passengers,
            'total_passengers' => $passengers->count(),
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

        return $this->paginated($vehicles->through(fn($v) => new VehicleResource($v)));
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
        $points = $vehicle->pickupPoints()->get();

        return $this->success($points->map(fn($p) => [
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
        $query = User::withCount('bookings')->with('roles');

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
                'roles' => $user->roles->pluck('name'),
                'bookings_count' => $user->bookings_count,
                'created_at' => $user->created_at?->toISOString(),
            ];
        }));
    }

    public function storeUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'in:admin,operator,customer'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
        ]);

        $user->assignRole($validated['role']);

        return $this->success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => $user->roles->pluck('name'),
        ], 'สร้างผู้ใช้สำเร็จ', 201);
    }

    public function updateUser(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'unique:users,email,' . $id],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['sometimes', 'in:admin,operator,customer'],
        ]);

        $userData = collect($validated)->except(['password', 'role'])->toArray();
        if (!empty($validated['password'])) {
            $userData['password'] = Hash::make($validated['password']);
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
        $points = $schedule->pickupPoints()->get();

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

        $validated['schedule_id'] = $scheduleId;

        $point = SchedulePickupPoint::updateOrCreate(
            ['schedule_id' => $scheduleId, 'region' => $validated['region']],
            $validated,
        );

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

    // ─── Image Upload ─────────────────────────────────────────

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'], // max 5MB
        ]);

        $file = $request->file('image');
        $filename = time() . '_' . Str::random(8) . '.' . $file->getClientOriginalExtension();

        // Move to public/images directory
        $file->move(public_path('images'), $filename);

        $url = '/images/' . $filename;

        return $this->success([
            'url' => $url,
            'filename' => $filename,
        ], 'อัปโหลดรูปภาพสำเร็จ');
    }
}
