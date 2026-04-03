<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyReward;
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
        $query = TripSchedule::with(['trip', 'vehicle', 'bookings']);

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
            $confirmedBookings = $s->bookings->where('status', 'confirmed')->count();
            $pendingBookings = $s->bookings->where('status', 'pending')->count();

            return [
                'id' => $s->id,
                'title' => $s->trip->title,
                'start' => $s->departure_date->format('Y-m-d'),
                'end' => $s->return_date ? $s->return_date->format('Y-m-d') : $s->departure_date->format('Y-m-d'),
                'trip_id' => $s->trip_id,
                'trip_type' => $s->trip->type,
                'trip_title' => $s->trip->title,
                'vehicle' => $s->vehicle?->name,
                'transport_type' => $s->transport_type,
                'total_seats' => $s->total_seats,
                'booked_seats' => $s->booked_seats,
                'available_seats' => $s->total_seats - $s->booked_seats,
                'confirmed_bookings' => $confirmedBookings,
                'pending_bookings' => $pendingBookings,
                'status' => $s->status,
                'price' => $s->price_override ?? $s->trip->price_per_person,
            ];
        });

        return $this->success($events);
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
            ->map(fn($b) => new BookingResource($b));

        $totalSpent = Booking::where('user_id', $id)
            ->where('status', 'confirmed')
            ->sum('paid_amount');

        $stats = [
            'total_bookings' => $user->bookings_count,
            'confirmed' => Booking::where('user_id', $id)->where('status', 'confirmed')->count(),
            'cancelled' => Booking::where('user_id', $id)->where('status', 'cancelled')->count(),
            'total_spent' => (float) $totalSpent,
            'total_passengers' => BookingPassenger::whereHas('booking', fn($q) => $q->where('user_id', $id))->count(),
        ];

        return $this->success([
            'customer' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
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

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('trip_id')) {
            $query->whereHas('schedule', fn($q) => $q->where('trip_id', $request->trip_id));
        }

        $bookings = $query->orderByDesc('created_at')->get();

        $summary = [
            'total_bookings' => $bookings->count(),
            'confirmed' => $bookings->where('status', 'confirmed')->count(),
            'pending' => $bookings->where('status', 'pending')->count(),
            'cancelled' => $bookings->where('status', 'cancelled')->count(),
            'total_revenue' => (float) $bookings->where('status', 'confirmed')->sum('paid_amount'),
            'total_passengers' => $bookings->sum(fn($b) => $b->passengers->count()),
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

        $bookings = Booking::where('status', 'confirmed')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->with('schedule.trip')
            ->get();

        // Group by month
        $monthly = $bookings->groupBy(fn($b) => $b->created_at->format('Y-m'))->map(function ($group, $month) {
            return [
                'month' => $month,
                'bookings_count' => $group->count(),
                'revenue' => (float) $group->sum('paid_amount'),
                'passengers' => $group->sum(fn($b) => $b->passengers_count ?? 0),
            ];
        })->values();

        // Group by trip
        $byTrip = $bookings->groupBy(fn($b) => $b->schedule?->trip?->title ?? 'ไม่ทราบ')->map(function ($group, $trip) {
            return [
                'trip' => $trip,
                'bookings_count' => $group->count(),
                'revenue' => (float) $group->sum('paid_amount'),
            ];
        })->values();

        $summary = [
            'period' => "$from ถึง $to",
            'total_revenue' => (float) $bookings->sum('paid_amount'),
            'total_bookings' => $bookings->count(),
        ];

        return $this->success([
            'summary' => $summary,
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

        if (!$booking) {
            return $this->error('ไม่พบการจองสำหรับ QR Code นี้', 404);
        }

        if ($booking->status !== 'confirmed') {
            return $this->error('การจองนี้ยังไม่ได้รับการยืนยัน (สถานะ: ' . $booking->status . ')', 422);
        }

        if ($booking->checked_in) {
            return $this->error('เช็คอินแล้วเมื่อ ' . $booking->checked_in_at->format('d/m/Y H:i'), 422);
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
            return $this->error('เช็คอินแล้วเมื่อ ' . $booking->checked_in_at->format('d/m/Y H:i'), 422);
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
            $query->whereHas('user', fn($q) => $q->where('name', 'like', "%{$request->search}%"))
                  ->orWhere('comment', 'like', "%{$request->search}%");
        }

        $reviews = $query->orderByDesc('created_at')->paginate($request->get('per_page', 15));

        return $this->paginated($reviews->through(fn($r) => [
            'id'               => $r->id,
            'user_name'        => $r->user?->name ?? '-',
            'user_email'       => $r->user?->email ?? '-',
            'trip_title'       => $r->trip?->title ?? '-',
            'booking_ref'      => $r->booking?->booking_ref ?? '-',
            'rating'           => $r->rating,
            'comment'          => $r->comment,
            'images'           => $r->images ?? [],
            'admin_reply'      => $r->admin_reply,
            'admin_replied_by' => $r->repliedBy?->name,
            'admin_replied_at' => $r->admin_replied_at?->toISOString(),
            'is_approved'      => $r->is_approved,
            'created_at'       => $r->created_at?->toISOString(),
        ]));
    }

    public function adminReplyReview(Request $request, int $id): JsonResponse
    {
        $review = Review::findOrFail($id);

        $validated = $request->validate([
            'reply' => ['required', 'string', 'max:2000'],
        ]);

        $review->update([
            'admin_reply'      => $validated['reply'],
            'admin_replied_by' => $request->user()->id,
            'admin_replied_at' => now(),
        ]);

        return $this->success(null, 'ตอบกลับรีวิวสำเร็จ');
    }

    public function adminToggleReviewApproval(int $id): JsonResponse
    {
        $review = Review::findOrFail($id);
        $review->update(['is_approved' => !$review->is_approved]);

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

        return $this->paginated($rewards->through(fn($r) => [
            'id'               => $r->id,
            'name'             => $r->name,
            'description'      => $r->description,
            'type'             => $r->type,
            'points_required'  => $r->points_required,
            'discount_value'   => $r->discount_value,
            'is_active'        => $r->is_active,
            'stock'            => $r->stock,
            'redemptions_count' => $r->redemptions_count,
            'created_at'       => $r->created_at?->toISOString(),
        ]));
    }

    public function adminStoreReward(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'type'            => ['required', 'in:discount_percent,discount_fixed,free_item'],
            'points_required' => ['required', 'integer', 'min:1'],
            'discount_value'  => ['nullable', 'numeric', 'min:0'],
            'is_active'       => ['sometimes', 'boolean'],
            'stock'           => ['nullable', 'integer', 'min:0'],
        ]);

        $reward = LoyaltyReward::create($validated);

        return $this->success($reward, 'สร้างของรางวัลสำเร็จ', 201);
    }

    public function adminUpdateReward(Request $request, int $id): JsonResponse
    {
        $reward = LoyaltyReward::findOrFail($id);

        $validated = $request->validate([
            'name'            => ['sometimes', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'type'            => ['sometimes', 'in:discount_percent,discount_fixed,free_item'],
            'points_required' => ['sometimes', 'integer', 'min:1'],
            'discount_value'  => ['nullable', 'numeric', 'min:0'],
            'is_active'       => ['sometimes', 'boolean'],
            'stock'           => ['nullable', 'integer', 'min:0'],
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

        $totalPointsIssued = \App\Models\LoyaltyTransaction::where('type', 'earn')->sum('points');
        $totalPointsRedeemed = abs(\App\Models\LoyaltyTransaction::where('type', 'redeem')->sum('points'));

        return $this->success([
            'total_accounts'       => $totalAccounts,
            'tier_counts'          => [
                'regular' => (int) ($tierCounts['regular'] ?? 0),
                'silver'  => (int) ($tierCounts['silver'] ?? 0),
                'gold'    => (int) ($tierCounts['gold'] ?? 0),
            ],
            'total_points_issued'  => (int) $totalPointsIssued,
            'total_points_redeemed' => (int) $totalPointsRedeemed,
        ]);
    }
}
