<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTransaction;
use App\Models\Review;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminExtendedController extends Controller
{
    use ApiResponse;

    // ─── Calendar Data ─────────────────────────────────────────

    public function calendarSchedules(Request $request): JsonResponse
    {
        $query = TripSchedule::with([
            'trip',
            'vehicle',
            'bookings.passengers',
            'bookings.seats',
            'bookings.pickupPoint',
            'bookings.user',
            'pickupPoints',
        ]);

        if ($request->filled('start')) {
            $query->where('departure_date', '>=', $request->start);
        }
        if ($request->filled('end')) {
            $query->where('departure_date', '<=', $request->end);
        }
        if ($request->filled('trip_id')) {
            $query->where('trip_id', $request->trip_id);
        }

        $schedules = $query->orderBy('departure_date')->get();

        $events = $schedules->map(function ($s) {
            $activeBookings = $s->bookings->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES);
            $regularBookings = $activeBookings->reject(fn ($booking) => (bool) $booking->is_join_trip);
            $joinTripBookings = $activeBookings->filter(fn ($booking) => (bool) $booking->is_join_trip);

            $confirmedBookings = $activeBookings->where('status', 'confirmed')->count();
            $pendingBookings = $activeBookings->where('status', 'pending')->count();
            $regularPassengersCount = $regularBookings->sum(fn ($booking) => $booking->passengers->count());
            $joinTripPassengersCount = $joinTripBookings->sum(fn ($booking) => $booking->passengers->count());
            $regularTotalAmount = $regularBookings->sum(fn ($booking) => (float) $booking->total_amount);
            $joinTripTotalAmount = $joinTripBookings->sum(fn ($booking) => (float) $booking->total_amount);
            // Aggregate add-ons across active bookings so admin can see at a
            // glance what to prepare for this departure (e.g. 8 t-shirts, 3
            // halal meals) and exactly which customer ticked each one. Each
            // add-on carries a `customers` list of {booking_ref, name, qty}.
            $addonsSummary = $activeBookings
                ->flatMap(function ($booking) {
                    return collect($booking->selected_addons ?? [])->map(fn ($addon) => array_merge($addon, [
                        '__booking_ref' => $booking->booking_ref,
                        '__customer_name' => $booking->user?->name,
                    ]));
                })
                ->groupBy('name')
                ->map(fn ($items, $name) => [
                    'name' => (string) $name,
                    'price_type' => $items->first()['price_type'] ?? 'per_booking',
                    'unit_price' => (float) ($items->first()['unit_price'] ?? 0),
                    'total_quantity' => (int) $items->sum(fn ($i) => (int) ($i['quantity'] ?? 0)),
                    'total_price' => (float) $items->sum(fn ($i) => (float) ($i['total_price'] ?? 0)),
                    'customers' => $items->map(fn ($i) => [
                        'booking_ref' => $i['__booking_ref'],
                        'name' => $i['__customer_name'],
                        'quantity' => (int) ($i['quantity'] ?? 0),
                    ])->values()->all(),
                ])
                ->values()
                ->all();

            $passengerManifest = $activeBookings
                ->flatMap(function ($booking) {
                    $seatLabels = $booking->seats->pluck('seat_id')->filter()->values()->all();

                    return $booking->passengers->map(function ($passenger) use ($booking, $seatLabels) {
                        return [
                            'id' => $passenger->id,
                            'booking_id' => $booking->id,
                            'booking_ref' => $booking->booking_ref,
                            'booking_status' => $booking->status,
                            'booking_type' => $booking->is_join_trip ? 'join_trip' : 'regular',
                            'booking_type_label' => $booking->is_join_trip ? 'จอยทริป' : 'จองปกติ',
                            'checked_in' => (bool) $booking->checked_in,
                            'title' => $passenger->title,
                            'name' => $passenger->name,
                            'nickname' => $passenger->nickname,
                            'id_card' => $passenger->id_card,
                            'birth_date' => $passenger->birth_date?->format('Y-m-d'),
                            'age' => $passenger->age,
                            'phone' => $passenger->phone ?: $booking->user?->phone,
                            'blood_group' => $passenger->blood_group,
                            'allergies' => $passenger->allergies,
                            'health_notes' => $passenger->health_notes,
                            'emergency_contact' => $passenger->emergency_contact,
                            'emergency_phone' => $passenger->emergency_phone,
                            'dive_cert_level' => $passenger->dive_cert_level,
                            'cert_number' => $passenger->cert_number,
                            'weight' => $passenger->weight,
                            'halal_food' => (bool) $passenger->halal_food,
                            'seat_labels' => $booking->is_join_trip ? [] : $seatLabels,
                            'pickup_region' => $booking->pickupPoint?->region_label ?: $booking->pickup_region,
                            'pickup_location' => $booking->pickupPoint?->pickup_location,
                            'customer_name' => $booking->user?->name,
                            'customer_phone' => $booking->user?->phone,
                            'payment_type' => $booking->payment_type,
                            'payment_method' => $booking->payment_method,
                            'total_amount' => (float) $booking->total_amount,
                            'paid_amount' => (float) $booking->paid_amount,
                        ];
                    });
                })
                ->values()
                ->all();

            return [
                'id' => $s->id,
                'title' => $s->trip->title,
                'start' => $s->departure_date->format('Y-m-d'),
                'end' => $s->return_date ? $s->return_date->format('Y-m-d') : $s->departure_date->format('Y-m-d'),
                'trip_id' => $s->trip_id,
                'trip_type' => $s->trip->type,
                'trip_title' => $s->trip->title,
                'trip_region' => $s->trip->region,
                'vehicle' => $s->vehicle?->name,
                'transport_type' => $s->transport_type,
                'total_seats' => $s->total_seats,
                'booked_seats' => $s->booked_seats,
                'available_seats' => $s->total_seats - $s->booked_seats,
                'confirmed_bookings' => $confirmedBookings,
                'pending_bookings' => $pendingBookings,
                'regular_passengers_count' => $regularPassengersCount,
                'regular_total_amount' => $regularTotalAmount,
                'join_trip_enabled' => (bool) $s->join_trip_enabled,
                'join_trip_price' => $s->join_trip_price ? (float) $s->join_trip_price : null,
                'join_trip_passengers_count' => $joinTripPassengersCount,
                'join_trip_total_amount' => $joinTripTotalAmount,
                'total_passengers' => $regularPassengersCount + $joinTripPassengersCount,
                'total_amount' => $regularTotalAmount + $joinTripTotalAmount,
                'passenger_manifest' => $passengerManifest,
                'addons_summary' => $addonsSummary,
                'status' => $s->status,
                'price' => $s->price_override ?? $s->trip->price_per_person,
                'pickup_points' => $s->pickupPoints->map(fn ($pt) => [
                    'id' => $pt->id,
                    'region' => $pt->region,
                    'region_label' => $pt->region_label,
                    'pickup_location' => $pt->pickup_location,
                    'price' => (float) $pt->price,
                    'notes' => $pt->notes,
                    'pickup_time' => $pt->pickup_time,
                    'map_url' => $pt->map_url,
                ])->values()->all(),
            ];
        });

        return $this->success($events);
    }

    /**
     * Inline-edit a booking passenger from the schedule manifest — used to fill in a
     * birth date retroactively for bookings made before the field existed. Returns the
     * updated birth_date and freshly computed age.
     */
    public function updatePassenger(Request $request, int $id): JsonResponse
    {
        $passenger = BookingPassenger::findOrFail($id);

        $validated = $request->validate([
            'birth_date' => ['nullable', 'date', 'before:today'],
        ], [
            'birth_date.before' => 'วัน/เดือน/ปีเกิดไม่ถูกต้อง',
        ]);

        $passenger->update($validated);
        $passenger->refresh();

        return $this->success([
            'id' => $passenger->id,
            'birth_date' => $passenger->birth_date?->format('Y-m-d'),
            'age' => $passenger->age,
        ], 'อัปเดตข้อมูลผู้เดินทางแล้ว');
    }

    /**
     * Upcoming, still-active bookings that have at least one passenger without a
     * birth date — each with a ready per-booking link to send the customer so they
     * can fill in birth dates for everyone in the booking (covers booked-for-friends).
     */
    public function birthdateFollowup(Request $request): JsonResponse
    {
        $bookings = Booking::query()
            ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
            ->whereHas('schedule', fn ($s) => $s->whereDate('departure_date', '>=', now()->toDateString()))
            ->whereHas('passengers', fn ($p) => $p->whereNull('birth_date'))
            ->with(['user', 'schedule.trip', 'passengers'])
            ->get()
            ->sortBy(fn ($b) => $b->schedule?->departure_date)
            ->map(function ($b) {
                $total = $b->passengers->count();
                $missing = $b->passengers->whereNull('birth_date')->count();

                return [
                    'booking_id' => $b->id,
                    'booking_ref' => $b->booking_ref,
                    'customer_name' => $b->user?->name,
                    'customer_phone' => $b->user?->phone,
                    'trip_title' => $b->schedule?->trip?->title,
                    'departure_date' => $b->schedule?->departure_date?->format('Y-m-d'),
                    'total_passengers' => $total,
                    'filled_count' => $total - $missing,
                    'missing_count' => $missing,
                    'link' => $b->birthdateUrl(), // lazily mints the token
                ];
            })
            ->values();

        return $this->success($bookings);
    }

    // ─── Customer Management ───────────────────────────────────

    public function customers(Request $request): JsonResponse
    {
        $query = User::role('customer')
            ->withCount('bookings')
            ->with('roles');

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
                    ->orWhere('phone', 'like', "%{$request->search}%");
            });
        }

        if ($request->filled('sort')) {
            match ($request->sort) {
                'bookings' => $query->orderByDesc('bookings_count'),
                'name' => $query->orderBy('name'),
                'newest' => $query->orderByDesc('created_at'),
                default => $query->orderByDesc('created_at'),
            };
        } else {
            $query->orderByDesc('created_at');
        }

        $customers = $query->paginate($request->get('per_page', 15));

        return $this->paginated($customers->through(function ($user) {
            $totalSpent = Booking::where('user_id', $user->id)
                ->where('status', 'confirmed')
                ->sum('paid_amount');

            $lastBooking = Booking::where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->first();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar_url' => $user->avatar_url,
                'bookings_count' => $user->bookings_count,
                'total_spent' => (float) $totalSpent,
                'last_booking_at' => $lastBooking?->created_at?->toISOString(),
                'created_at' => $user->created_at?->toISOString(),
            ];
        }));
    }

    public function customerDetail(int $id): JsonResponse
    {
        $user = User::withCount('bookings')->findOrFail($id);

        $bookings = Booking::where('user_id', $id)
            ->with(['schedule.trip', 'passengers', 'seats'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($b) => new BookingResource($b));

        $totalSpent = Booking::where('user_id', $id)
            ->where('status', 'confirmed')
            ->sum('paid_amount');

        $stats = [
            'total_bookings' => $user->bookings_count,
            'confirmed' => Booking::where('user_id', $id)->where('status', 'confirmed')->count(),
            'cancelled' => Booking::where('user_id', $id)->where('status', 'cancelled')->count(),
            'total_spent' => (float) $totalSpent,
            'total_passengers' => BookingPassenger::whereHas('booking', fn ($q) => $q->where('user_id', $id))->count(),
        ];

        return $this->success([
            'customer' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar_url' => $user->avatar_url,
                'created_at' => $user->created_at?->toISOString(),
            ],
            'stats' => $stats,
            'bookings' => $bookings,
        ]);
    }

    // ─── Vehicle Maintenance ───────────────────────────────────

    public function maintenances(Request $request): JsonResponse
    {
        $query = VehicleMaintenance::with('vehicle');

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $maintenances = $query->orderByDesc('scheduled_date')->paginate($request->get('per_page', 15));

        return $this->paginated($maintenances->through(function ($m) {
            return [
                'id' => $m->id,
                'vehicle_id' => $m->vehicle_id,
                'vehicle_name' => $m->vehicle->name,
                'vehicle_type' => $m->vehicle->type,
                'type' => $m->type,
                'title' => $m->title,
                'description' => $m->description,
                'scheduled_date' => $m->scheduled_date?->format('Y-m-d'),
                'completed_date' => $m->completed_date?->format('Y-m-d'),
                'status' => $m->status,
                'cost' => (float) $m->cost,
                'performed_by' => $m->performed_by,
                'notes' => $m->notes,
                'next_km' => $m->next_km,
                'created_at' => $m->created_at?->toISOString(),
            ];
        }));
    }

    public function storeMaintenance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'type' => ['required', 'in:routine,repair,inspection,insurance,registration'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'scheduled_date' => ['required', 'date'],
            'completed_date' => ['nullable', 'date'],
            'status' => ['sometimes', 'in:scheduled,in_progress,completed,overdue'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'performed_by' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'next_km' => ['nullable', 'integer', 'min:0'],
        ]);

        $maintenance = VehicleMaintenance::create($validated);

        return $this->success($maintenance->load('vehicle'), 'สร้างรายการบำรุงรักษาสำเร็จ', 201);
    }

    public function updateMaintenance(Request $request, int $id): JsonResponse
    {
        $maintenance = VehicleMaintenance::findOrFail($id);

        $validated = $request->validate([
            'vehicle_id' => ['sometimes', 'exists:vehicles,id'],
            'type' => ['sometimes', 'in:routine,repair,inspection,insurance,registration'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'scheduled_date' => ['sometimes', 'date'],
            'completed_date' => ['nullable', 'date'],
            'status' => ['sometimes', 'in:scheduled,in_progress,completed,overdue'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'performed_by' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'next_km' => ['nullable', 'integer', 'min:0'],
        ]);

        $maintenance->update($validated);

        return $this->success($maintenance->fresh()->load('vehicle'), 'อัปเดตรายการบำรุงรักษาสำเร็จ');
    }

    public function deleteMaintenance(int $id): JsonResponse
    {
        $maintenance = VehicleMaintenance::findOrFail($id);
        $maintenance->delete();

        return $this->success(null, 'ลบรายการบำรุงรักษาสำเร็จ');
    }

    // ─── Reports Export ────────────────────────────────────────

    public function reportBookings(Request $request): JsonResponse
    {
        $query = Booking::with(['schedule.trip', 'user', 'passengers', 'seats']);

        $dateField = $request->get('date_type') === 'travel' ? 'trip_schedules.departure_date' : 'bookings.created_at';

        if ($request->get('date_type') === 'travel') {
            $query->join('trip_schedules', 'bookings.schedule_id', '=', 'trip_schedules.id')
                ->select('bookings.*');
        }

        if ($request->filled('from')) {
            $query->whereDate($dateField, '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate($dateField, '<=', $request->to);
        }
        if ($request->filled('status')) {
            $query->where('bookings.status', $request->status);
        }
        if ($request->filled('trip_id')) {
            $query->whereHas('schedule', fn ($q) => $q->where('trip_id', $request->trip_id));
        }

        $bookings = $query->orderByDesc('created_at')->get();

        $summary = [
            'total_bookings' => $bookings->count(),
            'confirmed' => $bookings->where('status', 'confirmed')->count(),
            'pending' => $bookings->where('status', 'pending')->count(),
            'cancelled' => $bookings->where('status', 'cancelled')->count(),
            'total_revenue' => (float) $bookings->where('status', 'confirmed')->sum('paid_amount'),
            'total_passengers' => $bookings->sum(fn ($b) => $b->passengers->count()),
        ];

        $rows = $bookings->map(function ($b) {
            return [
                'booking_ref' => $b->booking_ref,
                'customer_name' => $b->user?->name ?? '-',
                'customer_email' => $b->user?->email ?? '-',
                'customer_phone' => $b->user?->phone ?? '-',
                'trip_title' => $b->schedule?->trip?->title ?? '-',
                'departure_date' => $b->schedule?->departure_date?->format('Y-m-d') ?? '-',
                'passengers_count' => $b->passengers->count(),
                'seats' => $b->seats->pluck('seat_id')->join(', '),
                'status' => $b->status,
                'total_amount' => (float) $b->total_amount,
                'paid_amount' => (float) $b->paid_amount,
                'payment_method' => $b->payment_method,
                'is_group' => $b->is_group ? 'ใช่' : 'ไม่',
                'group_name' => $b->group_name ?? '-',
                'created_at' => $b->created_at?->format('Y-m-d H:i'),
            ];
        });

        return $this->success([
            'summary' => $summary,
            'rows' => $rows,
        ]);
    }

    public function reportRevenue(Request $request): JsonResponse
    {
        $from = $request->get('from', now()->startOfYear()->format('Y-m-d'));
        $to = $request->get('to', now()->format('Y-m-d'));

        $bookings = Booking::whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->whereIn('status', ['confirmed', 'pending'])
            ->with(['schedule.trip'])
            ->withCount('passengers')
            ->get();

        $totalAmount = (float) $bookings->sum('total_amount');
        $paidAmount = (float) $bookings->sum('paid_amount');
        $remainingAmount = round($totalAmount - $paidAmount, 2);

        // Per payment_type breakdown
        $byPaymentType = $bookings->groupBy('payment_type')->map(function ($group, $type) {
            $groupTotal = (float) $group->sum('total_amount');
            $groupPaid = (float) $group->sum('paid_amount');

            return [
                'payment_type' => $type,
                'bookings_count' => $group->count(),
                'passengers_count' => $group->sum(fn ($b) => $b->passengers_count ?? 0),
                'total_amount' => $groupTotal,
                'paid_amount' => $groupPaid,
                'remaining_amount' => round($groupTotal - $groupPaid, 2),
            ];
        })->values();

        // Group by month
        $monthly = $bookings->groupBy(fn ($b) => $b->created_at->format('Y-m'))->map(function ($group, $month) {
            $mTotal = (float) $group->sum('total_amount');
            $mPaid = (float) $group->sum('paid_amount');

            return [
                'month' => $month,
                'bookings_count' => $group->count(),
                'passengers_count' => $group->sum(fn ($b) => $b->passengers_count ?? 0),
                'total_amount' => $mTotal,
                'paid_amount' => $mPaid,
                'remaining_amount' => round($mTotal - $mPaid, 2),
            ];
        })->sortKeys()->values();

        // Group by trip
        $byTrip = $bookings->groupBy(fn ($b) => $b->schedule?->trip?->title ?? 'ไม่ทราบ')->map(function ($group, $trip) {
            $tTotal = (float) $group->sum('total_amount');
            $tPaid = (float) $group->sum('paid_amount');

            return [
                'trip' => $trip,
                'bookings_count' => $group->count(),
                'passengers_count' => $group->sum(fn ($b) => $b->passengers_count ?? 0),
                'total_amount' => $tTotal,
                'paid_amount' => $tPaid,
                'remaining_amount' => round($tTotal - $tPaid, 2),
            ];
        })->sortByDesc('total_amount')->values();

        $summary = [
            'period' => "$from ถึง $to",
            'total_bookings' => $bookings->count(),
            'total_passengers' => $bookings->sum(fn ($b) => $b->passengers_count ?? 0),
            'total_amount' => $totalAmount,
            'paid_amount' => $paidAmount,
            'remaining_amount' => $remainingAmount,
        ];

        return $this->success([
            'summary' => $summary,
            'by_payment_type' => $byPaymentType,
            'monthly' => $monthly,
            'by_trip' => $byTrip,
        ]);
    }

    public function reportVehicles(Request $request): JsonResponse
    {
        $vehicles = Vehicle::withCount(['schedules', 'maintenances'])->get();

        $rows = $vehicles->map(function ($v) {
            $totalMaintenanceCost = $v->maintenances()->sum('cost');
            $upcomingMaintenance = $v->maintenances()
                ->where('status', 'scheduled')
                ->where('scheduled_date', '>=', now())
                ->orderBy('scheduled_date')
                ->first();

            $totalTrips = $v->schedules()->where('departure_date', '<', now())->count();
            $upcomingTrips = $v->schedules()->where('departure_date', '>=', now())->count();

            return [
                'id' => $v->id,
                'name' => $v->name,
                'type' => $v->type,
                'capacity' => $v->capacity,
                'total_trips' => $totalTrips,
                'upcoming_trips' => $upcomingTrips,
                'total_maintenances' => $v->maintenances_count,
                'total_maintenance_cost' => (float) $totalMaintenanceCost,
                'next_maintenance' => $upcomingMaintenance?->scheduled_date?->format('Y-m-d'),
                'next_maintenance_type' => $upcomingMaintenance?->type,
            ];
        });

        return $this->success($rows);
    }

    // ─── QR Code Check-in ──────────────────────────────────────

    public function checkIn(Request $request): JsonResponse
    {
        $request->validate([
            'qr_code' => ['required', 'string'],
        ]);

        $booking = Booking::where('qr_code', $request->qr_code)
            ->with(['schedule.trip', 'user', 'passengers', 'seats'])
            ->first();

        if (! $booking) {
            return $this->error('ไม่พบการจองสำหรับ QR Code นี้', 404);
        }

        if ($booking->status !== 'confirmed') {
            return $this->error('การจองนี้ยังไม่ได้รับการยืนยัน (สถานะ: '.$booking->status.')', 422);
        }

        if ($booking->checked_in) {
            return $this->error('เช็คอินแล้วเมื่อ '.$booking->checked_in_at->format('d/m/Y H:i'), 422);
        }

        $booking->update([
            'checked_in' => true,
            'checked_in_at' => now(),
        ]);

        return $this->success(new BookingResource($booking->fresh()), 'เช็คอินสำเร็จ');
    }

    public function checkInByRef(string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)
            ->with(['schedule.trip', 'user', 'passengers', 'seats'])
            ->firstOrFail();

        if ($booking->status !== 'confirmed') {
            return $this->error('การจองนี้ยังไม่ได้รับการยืนยัน', 422);
        }

        if ($booking->checked_in) {
            return $this->error('เช็คอินแล้วเมื่อ '.$booking->checked_in_at->format('d/m/Y H:i'), 422);
        }

        $booking->update([
            'checked_in' => true,
            'checked_in_at' => now(),
        ]);

        return $this->success(new BookingResource($booking->fresh()), 'เช็คอินสำเร็จ');
    }

    // ─── Admin Review Management ───────────────────────────────

    public function adminReviews(Request $request): JsonResponse
    {
        $query = Review::with(['user', 'trip', 'booking', 'repliedBy']);

        if ($request->filled('trip_id')) {
            $query->where('trip_id', $request->trip_id);
        }
        if ($request->filled('rating')) {
            $query->where('rating', $request->rating);
        }
        if ($request->filled('is_approved')) {
            $query->where('is_approved', $request->boolean('is_approved'));
        }
        if ($request->filled('search')) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
                ->orWhere('comment', 'like', "%{$request->search}%");
        }

        $reviews = $query->orderByDesc('created_at')->paginate($request->get('per_page', 15));

        return $this->paginated($reviews->through(fn ($r) => [
            'id' => $r->id,
            'user_name' => $r->user?->name ?? '-',
            'user_email' => $r->user?->email ?? '-',
            'trip_title' => $r->trip?->title ?? '-',
            'booking_ref' => $r->booking?->booking_ref ?? '-',
            'rating' => $r->rating,
            'comment' => $r->comment,
            'images' => $r->images ?? [],
            'videos' => $r->videos ?? [],
            'admin_reply' => $r->admin_reply,
            'admin_replied_by' => $r->repliedBy?->name,
            'admin_replied_at' => $r->admin_replied_at?->toISOString(),
            'is_approved' => $r->is_approved,
            'created_at' => $r->created_at?->toISOString(),
        ]));
    }

    public function adminReplyReview(Request $request, int $id): JsonResponse
    {
        $review = Review::findOrFail($id);

        $validated = $request->validate([
            'reply' => ['required', 'string', 'max:2000'],
        ]);

        $review->update([
            'admin_reply' => $validated['reply'],
            'admin_replied_by' => $request->user()->id,
            'admin_replied_at' => now(),
        ]);

        return $this->success(null, 'ตอบกลับรีวิวสำเร็จ');
    }

    public function adminToggleReviewApproval(int $id): JsonResponse
    {
        $review = Review::findOrFail($id);
        $review->update(['is_approved' => ! $review->is_approved]);

        $msg = $review->is_approved ? 'อนุมัติรีวิวแล้ว' : 'ซ่อนรีวิวแล้ว';

        return $this->success(['is_approved' => $review->is_approved], $msg);
    }

    public function adminDeleteReview(int $id): JsonResponse
    {
        Review::findOrFail($id)->delete();

        return $this->success(null, 'ลบรีวิวสำเร็จ');
    }

    // ─── Admin Loyalty Reward CRUD ─────────────────────────────

    public function adminRewards(Request $request): JsonResponse
    {
        $rewards = LoyaltyReward::withCount('redemptions')
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 15));

        return $this->paginated($rewards->through(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'description' => $r->description,
            'type' => $r->type,
            'points_required' => $r->points_required,
            'discount_value' => $r->discount_value,
            'is_active' => $r->is_active,
            'stock' => $r->stock,
            'redemptions_count' => $r->redemptions_count,
            'created_at' => $r->created_at?->toISOString(),
        ]));
    }

    public function adminStoreReward(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'in:discount_percent,discount_fixed,free_item'],
            'points_required' => ['required', 'integer', 'min:1'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'stock' => ['nullable', 'integer', 'min:0'],
        ]);

        $reward = LoyaltyReward::create($validated);

        return $this->success($reward, 'สร้างของรางวัลสำเร็จ', 201);
    }

    public function adminUpdateReward(Request $request, int $id): JsonResponse
    {
        $reward = LoyaltyReward::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['sometimes', 'in:discount_percent,discount_fixed,free_item'],
            'points_required' => ['sometimes', 'integer', 'min:1'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'stock' => ['nullable', 'integer', 'min:0'],
        ]);

        $reward->update($validated);

        return $this->success($reward->fresh(), 'อัปเดตของรางวัลสำเร็จ');
    }

    public function adminDeleteReward(int $id): JsonResponse
    {
        LoyaltyReward::findOrFail($id)->delete();

        return $this->success(null, 'ลบของรางวัลสำเร็จ');
    }

    public function adminLoyaltyStats(): JsonResponse
    {
        $totalAccounts = LoyaltyAccount::count();
        $tierCounts = LoyaltyAccount::selectRaw('tier, COUNT(*) as count')
            ->groupBy('tier')
            ->pluck('count', 'tier')
            ->toArray();

        $totalPointsIssued = LoyaltyTransaction::where('type', 'earn')->sum('points');
        $totalPointsRedeemed = abs(LoyaltyTransaction::where('type', 'redeem')->sum('points'));

        return $this->success([
            'total_accounts' => $totalAccounts,
            'tier_counts' => [
                'regular' => (int) ($tierCounts['regular'] ?? 0),
                'silver' => (int) ($tierCounts['silver'] ?? 0),
                'gold' => (int) ($tierCounts['gold'] ?? 0),
            ],
            'total_points_issued' => (int) $totalPointsIssued,
            'total_points_redeemed' => (int) $totalPointsRedeemed,
        ]);
    }
}
