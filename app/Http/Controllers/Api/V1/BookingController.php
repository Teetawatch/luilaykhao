<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CancelBookingRequest;
use App\Http\Requests\Booking\ChangePickupRequest;
use App\Http\Requests\Booking\CreateBookingRequest;
use App\Http\Requests\Booking\RescheduleBookingRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\SchedulePhotoResource;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\Review;
use App\Models\SchedulePhoto;
use App\Models\TripPost;
use App\Models\TripSchedule;
use App\Services\BookingService;
use App\Services\ModerationService;
use App\Services\WeatherService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private BookingService $bookingService,
        private WeatherService $weatherService,
        private ModerationService $moderation,
    ) {}

    public function store(CreateBookingRequest $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->createBooking(
                userId: $request->user()->id,
                scheduleId: $request->schedule_id,
                passengers: $request->passengers,
                seatIds: $request->seat_ids ?? [],
                pickupPointId: $request->pickup_point_id,
                vehicleOptionId: $request->vehicle_option_id ? (int) $request->vehicle_option_id : null,
                pickupRegion: $request->pickup_region,
                isGroup: (bool) $request->is_group,
                groupName: $request->group_name,
                groupNotes: $request->group_notes,
                promotionCode: $request->promotion_code,
                isJoinTrip: (bool) $request->is_join_trip,
                selectedAddons: $request->selected_addons ?? [],
                selectedRentals: $request->selected_rentals ?? [],
                customPickup: $request->filled('custom_pickup_lat') ? [
                    'label' => $request->custom_pickup_label,
                    'lat' => (float) $request->custom_pickup_lat,
                    'lng' => (float) $request->custom_pickup_lng,
                    'note' => $request->custom_pickup_note,
                ] : null,
                isGift: $request->boolean('is_gift'),
                giftFromName: $request->gift_from_name,
                giftMessage: $request->gift_message,
            );

            return $this->success(new BookingResource($booking), 'สร้างการจองสำเร็จ', 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * รายละเอียดการจองหนึ่งรายการ
     *
     * booking_ref เป็นรูปแบบที่เดาได้ (LLK-วันที่-เลขสี่หลัก) จึงกันสิทธิ์ที่ตัวผู้เรียก
     * ไม่ใช่ที่ความลับของรหัส — เจ้าของ เพื่อนที่ถูกเชิญ และทีมงานเท่านั้นที่เปิดดูได้
     * (กติกาเดียวกับ VehicleTrackingController::bookingTracking)
     */
    public function show(Request $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)
            ->with([
                'user',
                'schedule.trip',
                'schedule.pickupPoints',
                'schedule.staff',
                'schedule.vehicle',
                'pickupPoint',
                'seats',
                'passengers.pickupPoint',
                // เอกสารแนบที่ทริปขอ — หน้ารายละเอียดคือที่ที่ลูกค้าตามมาแนบทีหลัง
                'documents',
                'installmentPayments',
                'splitShares',
                // เฉพาะรีวิวของผู้ที่กำลังดู (เจ้าของหรือเพื่อนร่วมเดินทาง) เพื่อให้ can_review เป็นรายคน
                'review' => fn ($q) => $q->where('user_id', auth()->id()),
                'staffReviews' => fn ($q) => $q->where('reviewer_user_id', auth()->id()),
            ])
            ->firstOrFail();

        $user = $request->user();
        $isTeam = $user->hasAnyRole(['admin', 'operator', 'staff']);

        if (! $isTeam && ! $booking->isAccessibleByUser($user->id)) {
            return $this->error('คุณไม่มีสิทธิ์ดูข้อมูลการจองนี้', 403);
        }

        $this->attachWeather($booking);

        return $this->success(new BookingResource($booking));
    }

    /**
     * Best-effort: attach the departure-day forecast to the booking's schedule
     * so the booking detail screen can surface it. Never let a weather lookup
     * failure break the booking response.
     */
    private function attachWeather(Booking $booking): void
    {
        $schedule = $booking->schedule;
        $trip = $schedule?->trip;

        if (! $schedule || ! $trip || $trip->latitude === null || $trip->longitude === null) {
            return;
        }

        $date = $schedule->departure_date?->toDateString();
        if (! $date) {
            return;
        }

        try {
            $forecast = $this->weatherService->forecastFor(
                (float) $trip->latitude,
                (float) $trip->longitude,
                $date,
            );

            if ($forecast) {
                $schedule->weather_forecast = $forecast->toPayload();
            }
        } catch (\Throwable $e) {
            Log::warning('Unable to attach weather to booking', [
                'booking_ref' => $booking->booking_ref,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** สถานะที่ถือว่า "กำลังจะมาถึง" และ "ผ่านมาแล้ว" — ต้องตรงกับแท็บบนหน้าเว็บ. */
    private const UPCOMING_STATUSES = ['pending', 'confirmed'];

    private const PAST_STATUSES = ['cancelled', 'refunded', 'completed'];

    /**
     * รายการจองของผู้ใช้
     *
     * ค่าเริ่มต้นคืนทั้งหมดในครั้งเดียว เพราะแอปมือถือที่ปล่อยไปแล้วเก็บผลลัพธ์ลง
     * offline cache ทั้งก้อน — ถ้าเปลี่ยนไปแบ่งหน้าโดยปริยาย แอปจะเห็นแค่หน้าแรก
     * เงียบ ๆ. เว็บที่ต้องการแบ่งหน้าให้ส่ง per_page มาเอง
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'scope' => ['nullable', 'string', 'in:upcoming,past'],
        ]);

        $userId = $request->user()->id;

        // การจองที่ผู้ใช้เป็นเจ้าของ หรือถูกเชิญเข้าร่วมในฐานะเพื่อน (companion)
        $memberBookingIds = BookingMember::where('user_id', $userId)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->pluck('booking_id');

        // สร้างใหม่ทุกครั้งแทนการ clone เพื่อให้แน่ใจว่า query นับจำนวนไม่ติดเงื่อนไข
        // scope หรือ eager load ของ query หลักมาด้วย
        $mine = fn () => Booking::query()->where(function ($inner) use ($userId, $memberBookingIds) {
            $inner->where('user_id', $userId)
                ->orWhereIn('id', $memberBookingIds);
        });

        $query = $mine()
            ->when(
                isset($data['scope']),
                fn ($q) => $q->whereIn(
                    'status',
                    $data['scope'] === 'upcoming' ? self::UPCOMING_STATUSES : self::PAST_STATUSES,
                ),
            )
            ->with([
                'user',
                'schedule.trip',
                'schedule.pickupPoints',
                'schedule.staff',
                'schedule.vehicle',
                // ผู้ร่วมเดินทางทั้งหมดในรอบ (ทุกการจองที่ยัง active) สำหรับแสดงอวาตาร์
                'schedule.bookings' => fn ($q) => $q->select('id', 'schedule_id', 'user_id', 'status')
                    ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES),
                // เอาเฉพาะคอลัมน์ที่ใช้แสดงชื่อ — ไม่ดึง id_card/allergies/health_notes
                // ที่เข้ารหัสไว้ ไม่งั้นแค่เปิดหน้า "การจองของฉัน" ก็ต้องถอดรหัส
                // 3 ฟิลด์ต่อผู้โดยสารทุกคนของทุกรอบที่เกี่ยวข้อง
                'schedule.bookings.passengers' => fn ($q) => $q->select('id', 'booking_id', 'name', 'nickname'),
                'pickupPoint',
                'seats',
                'passengers.pickupPoint',
                'installmentPayments',
                'splitShares',
                // เฉพาะรีวิวของผู้ที่กำลังดู (เจ้าของหรือเพื่อนร่วมเดินทาง) เพื่อให้ can_review เป็นรายคน
                'review' => fn ($q) => $q->where('user_id', $userId),
                'staffReviews' => fn ($q) => $q->where('reviewer_user_id', $request->user()->id),
            ])
            ->orderByDesc('created_at');

        // จำนวนของแต่ละแท็บต้องนับจากการจองทั้งหมดของผู้ใช้ ไม่ใช่จากหน้าที่กำลังดู
        // หรือจาก scope ที่กรองอยู่ ไม่งั้นตัวเลขบนแท็บจะเปลี่ยนไปมาตามหน้า
        $meta = [
            'upcoming_count' => $mine()->whereIn('status', self::UPCOMING_STATUSES)->count(),
            'past_count' => $mine()->whereIn('status', self::PAST_STATUSES)->count(),
        ];

        if (! isset($data['per_page'])) {
            $bookings = $query->get();

            return $this->success(
                $bookings->map(fn ($b) => new BookingResource($b))->values(),
                meta: $meta,
            );
        }

        $bookings = $query->paginate($data['per_page']);

        return $this->paginated(
            $bookings->through(fn ($b) => new BookingResource($b)),
            meta: $meta,
        );
    }

    public function cancel(CancelBookingRequest $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->firstOrFail();

        try {
            $booking = $this->bookingService->cancelBooking($booking, $request->reason);

            return $this->success(new BookingResource($booking), 'ยกเลิกการจองสำเร็จ');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function reschedule(RescheduleBookingRequest $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)
            ->where('user_id', $request->user()->id)
            ->whereIn('status', Booking::MODIFIABLE_STATUSES)
            ->firstOrFail();

        try {
            $booking = $this->bookingService->rescheduleBooking(
                $booking,
                (int) $request->target_schedule_id,
                $request->seat_ids ?? [],
                $request->pickup_point_id,
            );

            return $this->success(new BookingResource($booking), 'เปลี่ยนวันเดินทางสำเร็จ');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function changePickup(ChangePickupRequest $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)
            ->where('user_id', $request->user()->id)
            ->whereIn('status', Booking::MODIFIABLE_STATUSES)
            ->firstOrFail();

        try {
            $booking = $this->bookingService->changePickupPoint($booking, (int) $request->pickup_point_id);

            return $this->success(new BookingResource($booking), 'เปลี่ยนจุดรับสำเร็จ');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Photos taken by staff during the customer's trip, downloadable from
     * the customer app. Restricted to the booking owner; cancelled bookings
     * are excluded.
     */
    public function photos(Request $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)
            ->where('user_id', $request->user()->id)
            ->whereNotIn('status', ['cancelled'])
            ->with('schedule.photos')
            ->firstOrFail();

        $photos = $booking->schedule?->photos ?? collect();

        return $this->success(SchedulePhotoResource::collection($photos));
    }

    /**
     * ลิงก์อัลบั้มสาธารณะของรอบนี้ ถ้าทีมงานเปิดแชร์ไว้แล้ว
     *
     * แอปแสดงรูปเองได้อยู่แล้ว (photos ด้านบน) สิ่งที่ต้องพึ่งหน้าเว็บคือ
     * "ค้นหารูปตัวเองด้วยใบหน้า" ซึ่งประมวลผลบนเครื่องผู้ใช้ในเบราว์เซอร์ทั้งหมด
     *
     * ตั้งใจไม่สร้าง token ให้เองเมื่อยังไม่มี — การเปิดลิงก์สาธารณะเป็นการ
     * ตัดสินใจของทีมงาน ไม่ใช่ผลข้างเคียงของการที่ลูกค้ากดเปิดหน้าอัลบั้ม
     */
    public function album(Request $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)
            ->whereNotIn('status', ['cancelled'])
            ->with('schedule.photos')
            ->firstOrFail();

        if (! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('ไม่พบการจองนี้', 404);
        }

        $schedule = $booking->schedule;
        $photos = $schedule?->photos ?? collect();

        return $this->success([
            'album_url' => $schedule?->photoAlbumUrl(),
            'count' => $photos->count(),
            'expires_at' => $photos
                ->map(fn (SchedulePhoto $photo) => $photo->expiresAt())
                ->filter()
                ->min()?->toISOString(),
        ]);
    }

    /**
     * Trip Recap — การ์ดสรุปทริปแบบ story หลังจบทริป (สายเดินป่า Wrapped รายทริป).
     * รวมสถิติจากทริป/รอบ/กลุ่ม + รูปเด่นจากฟีดหลังทริป เพื่อให้ลูกค้าแชร์อวดได้.
     */
    public function recap(Request $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)
            ->with(['schedule.trip', 'passengers'])
            ->firstOrFail();

        if (! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('ไม่พบการจองนี้', 404);
        }

        if ($booking->status === 'cancelled') {
            return $this->error('การจองนี้ถูกยกเลิกแล้ว', 422);
        }

        $schedule = $booking->schedule;
        $trip = $schedule?->trip;

        if (! $schedule || ! $trip) {
            return $this->error('ไม่พบข้อมูลทริปสำหรับการจองนี้', 404);
        }

        // รอบนี้ถือว่า "จบทริป" เมื่อวันเดินทาง (หรือวันกลับ) ผ่านมาแล้ว.
        $endDate = $schedule->return_date ?? $schedule->departure_date;
        $tripCompleted = $endDate !== null && $endDate->copy()->endOfDay()->isPast();

        // รูปเด่น: โพสต์ที่เผยแพร่ในฟีดของรอบนี้ (fallback เป็นทั้งทริป) เรียงตามยอดไลก์.
        $photos = TripPost::query()
            ->where('status', TripPost::STATUS_PUBLISHED)
            ->where(function ($q) use ($schedule, $trip) {
                $q->where('schedule_id', $schedule->id)
                    ->orWhere('trip_id', $trip->id);
            })
            ->orderByDesc('likes_count')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->flatMap(fn (TripPost $post) => collect($post->photos ?? [])
                ->pluck('url')
                ->filter())
            ->take(6)
            ->values()
            ->all();

        // รูปจากรีวิว — ใช้เป็นพื้นหลังของสไลด์สรุป แทนพื้นสีล้วน รูปของคนที่ไป
        // "รอบเดียวกัน" มาก่อนเสมอ เพราะเป็นวิวเดียวกับที่เจ้าของ recap เห็นจริง
        $blockedAuthors = $this->moderation->hiddenAuthorIds($request->user());

        $reviews = Review::with(['user', 'booking:id,schedule_id'])
            ->where('trip_id', $trip->id)
            ->where('is_approved', true)
            ->whereNotNull('images')
            ->when($blockedAuthors, fn ($q) => $q->whereNotIn('user_id', $blockedAuthors))
            ->orderByDesc('created_at')
            ->limit(60)
            ->get();

        $sameRound = fn (Review $r) => $r->booking?->schedule_id === $schedule->id;

        $reviewPhotos = $reviews->filter($sameRound)
            ->concat($reviews->reject($sameRound))
            ->flatMap(fn (Review $r) => collect($r->images ?? [])
                ->filter()
                ->map(fn ($url) => [
                    'url' => $url,
                    'user_name' => $r->user?->name ?? 'เพื่อนร่วมทาง',
                    'user_avatar' => $r->user?->avatar_url,
                    'rating' => $r->rating,
                    'same_round' => $sameRound($r),
                ]))
            ->take(8)
            ->values()
            ->all();

        $difficultyLabels = [
            'easy' => 'สายชิล',
            'medium' => 'ปานกลาง',
            'hard' => 'สายโหด',
        ];

        $hasReviewed = $booking->review()
            ->where('user_id', $request->user()->id)
            ->exists();

        return $this->success([
            'booking_ref' => $booking->booking_ref,
            'trip_completed' => $tripCompleted,
            'trip' => [
                'title' => $trip->title,
                'slug' => $trip->slug,
                'location' => $trip->location,
                'region' => $trip->region,
                'difficulty' => $trip->difficulty,
                'difficulty_label' => $difficultyLabels[$trip->difficulty] ?? $trip->difficulty,
                'cover_image' => $trip->cover_image,
            ],
            'departure_date' => $schedule->departure_date?->toDateString(),
            'return_date' => $schedule->return_date?->toDateString(),
            'departs_at' => $schedule->departs_at?->toISOString(),
            'duration_days' => $trip->duration_days,
            'distance_km' => $trip->distance_km !== null ? (float) $trip->distance_km : null,
            'elevation_gain_m' => $trip->elevation_gain_m,
            'group_size' => $booking->passengers->count(),
            'total_travelers' => (int) $schedule->booked_seats,
            'photos' => $photos,
            'review_photos' => $reviewPhotos,
            'has_reviewed' => $hasReviewed,
        ]);
    }
}
