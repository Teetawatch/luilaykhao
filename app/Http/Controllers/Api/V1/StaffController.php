<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\StaffReview;
use App\Models\TripSchedule;
use App\Services\OutstandingPaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OutstandingPaymentService $outstandingPaymentService,
    ) {}

    public function mySchedules(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('staff')) {
            return $this->error('สิทธิ์ไม่เพียงพอสำหรับเมนูสตาฟ', 403);
        }

        $userId = $request->user()->id;

        $schedules = TripSchedule::with(['trip', 'vehicle', 'pickupPoints'])
            ->whereHas('activeStaff', fn ($q) => $q->where('users.id', $userId))
            ->orderBy('departure_date')
            ->get();

        $summary = StaffReview::where('staff_user_id', $userId)
            ->selectRaw('COUNT(*) as total_reviews, AVG(rating) as avg_rating')
            ->first();

        $today = now()->toDateString();

        return $this->success([
            'summary' => [
                'total_reviews' => (int) ($summary?->total_reviews ?? 0),
                'avg_rating' => $summary?->avg_rating ? round((float) $summary->avg_rating, 2) : null,
                'total_schedules' => $schedules->count(),
                'upcoming_count' => $schedules->filter(fn ($s) => $s->departure_date?->toDateString() >= $today)->count(),
            ],
            'schedules' => $schedules->map(function ($s) {
                // Count individual *passengers*, not bookings — a single group
                // booking can carry multiple travelers and the staff manifest
                // needs the headcount, matching the admin manifest endpoint.
                $bookings = Booking::where('schedule_id', $s->id)
                    ->whereIn('status', ['confirmed', 'completed'])
                    ->with(['passengers:id,booking_id,pickup_point_id,name,nickname,phone'])
                    ->get(['id', 'pickup_point_id', 'checked_in']);

                // Flatten each booking into per-passenger rows so headcount,
                // per-pickup tallies and the per-point roster are all accurate.
                $passengerRows = $bookings->flatMap(function ($b) {
                    return $b->passengers->map(fn ($p) => [
                        // Passengers can override the booking-level pickup
                        // (added 2026-05); fall back to the booking when null.
                        'pickup_point_id' => $p->pickup_point_id ?? $b->pickup_point_id,
                        'checked_in' => (bool) $b->checked_in,
                        'name' => trim((string) ($p->nickname ?: $p->name)) ?: 'ผู้โดยสาร',
                        'phone' => $p->phone,
                    ]);
                });

                $totalConfirmed = $passengerRows->count();
                $checkedInCount = $passengerRows->where('checked_in', true)->count();

                // แปลงกลุ่มผู้โดยสารเป็นรายชื่อย่อ (ชื่อ/เบอร์/สถานะเช็คอิน) ให้แอป
                // กดขยายแต่ละจุดแล้วเห็นว่าใครขึ้นจุดไหนได้เลย
                $roster = fn ($rows) => $rows
                    ->map(fn ($r) => [
                        'name' => $r['name'],
                        'phone' => $r['phone'],
                        'checked_in' => $r['checked_in'],
                    ])
                    ->values();

                $pickupBreakdown = $s->pickupPoints
                    ->map(function ($point) use ($passengerRows, $roster) {
                        $rows = $passengerRows->where('pickup_point_id', $point->id);

                        return [
                            'id' => $point->id,
                            'label' => $point->pickup_location ?: $point->region_label,
                            'region_label' => $point->region_label,
                            'passenger_count' => $rows->count(),
                            'passengers' => $roster($rows),
                        ];
                    })
                    ->filter(fn ($p) => $p['passenger_count'] > 0)
                    ->values();

                $noPickupRows = $passengerRows->whereNull('pickup_point_id');
                $noPickupCount = $noPickupRows->count();

                return [
                    'id' => $s->id,
                    'trip' => [
                        'id' => $s->trip?->id,
                        'title' => $s->trip?->title,
                        'location' => $s->trip?->location,
                        'cover_image' => $s->trip?->cover_image,
                    ],
                    'vehicle' => $s->vehicle ? [
                        'id' => $s->vehicle->id,
                        'name' => $s->vehicle->name,
                        'type' => $s->vehicle->type,
                    ] : null,
                    'departure_date' => $s->departure_date?->toDateString(),
                    'return_date' => $s->return_date?->toDateString(),
                    'status' => $s->status,
                    'transport_type' => $s->transport_type,
                    'total_seats' => $s->total_seats,
                    'booked_seats' => $s->booked_seats,
                    'total_confirmed' => $totalConfirmed,
                    'checked_in_count' => $checkedInCount,
                    'pickup_breakdown' => $pickupBreakdown,
                    'no_pickup_count' => $noPickupCount,
                    'no_pickup_passengers' => $roster($noPickupRows),
                ];
            })->values(),
        ]);
    }

    /**
     * ยอดค้างชำระของรอบเดินทางที่สตาฟคนนี้รับผิดชอบ
     *
     * ใช้หน้างาน: ลูกค้าผ่อนชำระบางคนลืมจ่ายงวดที่เหลือ สตาฟเปิดรายการนี้
     * แล้วให้ลูกค้าสแกน QR ที่ชี้ไปหน้า /pay/{token} บนมือถือของลูกค้าเอง
     * (สลิปอยู่ในแอปธนาคารลูกค้า สตาฟจึงไม่ควรเป็นคนแนบ)
     */
    public function outstanding(Request $request, int $scheduleId): JsonResponse
    {
        if (! $request->user()->hasRole('staff')) {
            return $this->error('สิทธิ์ไม่เพียงพอสำหรับเมนูสตาฟ', 403);
        }

        $schedule = $this->assignedSchedule($request, $scheduleId);

        if (! $schedule) {
            return $this->error('คุณไม่ได้รับผิดชอบรอบเดินทางนี้', 403);
        }

        $rows = $this->outstandingPaymentService->rows($schedule->id);

        return $this->success([
            'schedule' => [
                'id' => $schedule->id,
                'trip_title' => $schedule->trip?->title,
                'departure_date' => $schedule->departure_date?->toDateString(),
            ],
            'count' => $rows->count(),
            'total_due' => round((float) $rows->sum('amount_due'), 2),
            'items' => $rows->values()->all(),
        ]);
    }

    /**
     * ส่งลิงก์ชำระเงินซ้ำให้ลูกค้าที่ค้างชำระในรอบที่สตาฟรับผิดชอบ
     * (เผื่อลูกค้าสแกน QR ไม่ได้ หรืออยากได้ลิงก์ไว้จ่ายทีหลัง)
     */
    public function sendPaymentLink(Request $request, int $scheduleId, string $ref): JsonResponse
    {
        if (! $request->user()->hasRole('staff')) {
            return $this->error('สิทธิ์ไม่เพียงพอสำหรับเมนูสตาฟ', 403);
        }

        if (! $this->assignedSchedule($request, $scheduleId)) {
            return $this->error('คุณไม่ได้รับผิดชอบรอบเดินทางนี้', 403);
        }

        $validated = $request->validate([
            'channels' => ['nullable', 'array'],
            'channels.*' => ['in:email,sms'],
        ]);

        // จำกัดให้ส่งได้เฉพาะการจองในรอบที่รับผิดชอบ — กัน staff ยิง ref ของรอบอื่น
        $booking = Booking::where('booking_ref', $ref)
            ->where('schedule_id', $scheduleId)
            ->first();

        if (! $booking) {
            return $this->error('ไม่พบการจองนี้ในรอบเดินทาง', 404);
        }

        try {
            $row = $this->outstandingPaymentService->sendLink(
                $booking,
                $validated['channels'] ?? ['email'],
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($row, 'ส่งลิงก์ชำระเงินแล้ว');
    }

    /**
     * รอบเดินทางที่ user คนนี้ยังถูก assign อยู่ (activeStaff = ยังไม่ถูกปลด)
     */
    private function assignedSchedule(Request $request, int $scheduleId): ?TripSchedule
    {
        return TripSchedule::with('trip')
            ->whereKey($scheduleId)
            ->whereHas('activeStaff', fn ($q) => $q->where('users.id', $request->user()->id))
            ->first();
    }

    public function myReviews(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('staff')) {
            return $this->error('สิทธิ์ไม่เพียงพอสำหรับเมนูสตาฟ', 403);
        }

        $reviews = StaffReview::with(['reviewer', 'schedule.trip'])
            ->where('staff_user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 20));

        return $this->paginated($reviews->through(fn ($review) => [
            'id' => $review->id,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'reviewer_name' => $review->reviewer?->name,
            'trip_title' => $review->schedule?->trip?->title,
            'departure_date' => $review->schedule?->departure_date?->toDateString(),
            'created_at' => $review->created_at?->toISOString(),
        ]));
    }

    public function storeReview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_id' => ['required', 'integer', 'exists:bookings,id'],
            'staff_user_id' => ['required', 'integer', 'exists:users,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $booking = Booking::with('schedule.staff')
            ->where('id', $validated['booking_id'])
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['confirmed', 'completed'])
            ->firstOrFail();

        $isAssigned = $booking->schedule?->staff
            ?->contains(fn ($staff) => (int) $staff->id === (int) $validated['staff_user_id']);

        if (! $isAssigned) {
            return $this->error('ไม่พบสตาฟคนนี้ในรอบเดินทางของการจองนี้', 422);
        }

        $existing = StaffReview::where('booking_id', $booking->id)
            ->where('staff_user_id', $validated['staff_user_id'])
            ->first();

        if ($existing) {
            $existing->update([
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
            ]);

            return $this->success([
                'id' => $existing->id,
                'booking_id' => $existing->booking_id,
                'staff_user_id' => $existing->staff_user_id,
                'rating' => $existing->rating,
                'comment' => $existing->comment,
                'updated_at' => $existing->updated_at?->toISOString(),
            ], 'อัปเดตรีวิวสตาฟสำเร็จ');
        }

        $review = StaffReview::create([
            'booking_id' => $booking->id,
            'schedule_id' => $booking->schedule_id,
            'reviewer_user_id' => $request->user()->id,
            'staff_user_id' => $validated['staff_user_id'],
            'rating' => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
        ]);

        return $this->success([
            'id' => $review->id,
            'booking_id' => $review->booking_id,
            'staff_user_id' => $review->staff_user_id,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'created_at' => $review->created_at?->toISOString(),
        ], 'รีวิวสตาฟสำเร็จ', 201);
    }
}
