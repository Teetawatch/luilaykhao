<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Stay;
use App\Models\StayBooking;
use App\Models\StayPayout;
use App\Models\StayRoomType;
use App\Models\Trip;
use App\Services\StayAvailabilityService;
use App\Services\StayBookingService;
use App\Services\StayPresenter;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ที่พักชุมชน ฝั่งลูกค้า — ค้นหา ดูห้องว่าง จอง จ่าย ยกเลิก รีวิว
 */
class StayController extends Controller
{
    use ApiResponse;

    public function __construct(
        private StayBookingService $bookings,
        private StayAvailabilityService $availability,
    ) {}

    // ── สาธารณะ ─────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'province' => ['nullable', 'string', 'max:80'],
            'type' => ['nullable', 'string', 'max:20'],
            'trip' => ['nullable', 'string', 'max:200'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $tripId = null;
        if (filled($data['trip'] ?? null)) {
            $tripId = Trip::where('slug', $data['trip'])->orWhere('id', ctype_digit($data['trip']) ? (int) $data['trip'] : 0)->value('id');
        }

        $stays = Stay::active()
            ->with('roomTypes')
            ->when($data['province'] ?? null, fn ($q, $p) => $q->where('province', $p))
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($tripId, fn ($q, $id) => $q->whereHas('trips', fn ($t) => $t->where('trips.id', $id)))
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('community_name', 'like', '%'.$term.'%')
                ->orWhere('province', 'like', '%'.$term.'%')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success([
            'stays' => $stays->map(fn (Stay $s) => StayPresenter::card($s))->values()->all(),
            'filters' => [
                'provinces' => Stay::active()->whereNotNull('province')->distinct()->orderBy('province')->pluck('province')->values()->all(),
                'types' => collect(Stay::TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            ],
            'impact' => self::impact(),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $stay = Stay::active()->where('slug', $slug)->with(['roomTypes', 'experiences', 'trips'])->firstOrFail();
        $stay->increment('views_count');

        return $this->success(StayPresenter::detail($stay));
    }

    /** ห้องว่างและราคาของทุกห้องในวันที่เลือก */
    public function availability(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], ['check_out.after' => 'วันออกต้องอยู่หลังวันเข้าพัก']);

        $stay = Stay::active()->where('slug', $slug)->with('roomTypes')->firstOrFail();
        $guests = (int) ($data['guests'] ?? 2);

        $rooms = $stay->roomTypes->where('is_active', true)->map(function (StayRoomType $room) use ($stay, $data, $guests) {
            $free = $this->availability->availableUnits($room, $data['check_in'], $data['check_out']);
            // ห้องที่ต้องใช้อย่างน้อยกี่ห้องให้พอคน
            $unitsNeeded = max(1, (int) ceil($guests / max(1, $room->max_guests)));
            $quote = null;
            if ($free >= $unitsNeeded) {
                try {
                    $quote = $this->availability->quote($stay, $room, $data['check_in'], $data['check_out'], $unitsNeeded, $guests);
                } catch (\Exception) {
                    $quote = null;
                }
            }

            return StayPresenter::room($room) + [
                'available_units' => $free,
                'units_needed' => $unitsNeeded,
                'quote' => $quote ? [
                    'nights' => $quote['nights'],
                    'room_amount' => $quote['room_amount'],
                    'extra_guest_amount' => $quote['extra_guest_amount'],
                    'total_amount' => $quote['total_amount'],
                    'host_amount' => $quote['host_amount'],
                ] : null,
            ];
        })->values()->all();

        return $this->success([
            'check_in' => $data['check_in'],
            'check_out' => $data['check_out'],
            'guests' => $guests,
            'rooms' => $rooms,
        ]);
    }

    /** ยอดที่แน่นอนก่อนกดจอง (ห้อง จำนวนห้อง กิจกรรม) */
    public function quote(Request $request, string $slug): JsonResponse
    {
        $data = $this->validateBookingInput($request, forQuote: true);
        $stay = Stay::active()->where('slug', $slug)->firstOrFail();
        $room = StayRoomType::where('stay_id', $stay->id)->where('is_active', true)->findOrFail($data['room_type_id']);

        try {
            $quote = $this->availability->quote($stay, $room, $data['check_in'], $data['check_out'], (int) ($data['units'] ?? 1), (int) $data['guests'], $data['experiences'] ?? []);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $quote['available_units'] = $this->availability->availableUnits($room, $data['check_in'], $data['check_out']);

        return $this->success($quote);
    }

    public function forTrip(string $slug): JsonResponse
    {
        $trip = Trip::where('slug', $slug)->firstOrFail();
        $stays = $trip->stays()->active()->with('roomTypes')->orderBy('sort_order')->get();

        return $this->success($stays->map(fn (Stay $s) => StayPresenter::card($s, $s->pivot?->distance_note))->values()->all());
    }

    // ── ลูกค้าที่ล็อกอิน ────────────────────────────────────────────

    public function store(Request $request, string $slug): JsonResponse
    {
        $data = $this->validateBookingInput($request);
        $stay = Stay::where('slug', $slug)->firstOrFail();

        try {
            $booking = $this->bookings->create($request->user(), $stay, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $booking->load(['stay', 'tripBooking']);

        return $this->success(
            StayPresenter::booking($booking),
            $booking->status === StayBooking::STATUS_REQUESTED
                ? 'ส่งคำขอแล้ว ที่พักจะยืนยันห้องว่างภายใน 24 ชั่วโมง แล้วค่อยชำระเงินครับ'
                : 'จองแล้ว กรุณาชำระเงินภายใน '.config('stays.instant_payment_minutes', 30).' นาทีเพื่อยืนยันห้อง',
            201,
        );
    }

    public function myBookings(Request $request): JsonResponse
    {
        $items = StayBooking::with(['stay', 'review', 'tripBooking'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return $this->success($items->map(fn (StayBooking $b) => StayPresenter::booking($b))->values()->all());
    }

    public function showBooking(Request $request, string $ref): JsonResponse
    {
        return $this->success(StayPresenter::booking($this->own($request, $ref)));
    }

    public function payment(Request $request, string $ref): JsonResponse
    {
        $booking = $this->own($request, $ref);
        if (! $booking->canPay()) {
            return $this->error('ใบจองนี้ไม่ได้รอชำระเงิน', 422);
        }

        return $this->success($this->bookings->paymentInfo($booking));
    }

    public function uploadSlip(Request $request, string $ref): JsonResponse
    {
        $request->validate([
            'slip_image' => ['required', 'image', 'max:10240'],
        ], ['slip_image.required' => 'กรุณาแนบสลิปการโอน']);

        $booking = $this->own($request, $ref);

        try {
            $booking = $this->bookings->submitSlip($booking, $request->file('slip_image'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $booking->load(['stay', 'review', 'tripBooking']);

        return $this->success(
            StayPresenter::booking($booking),
            $booking->status === StayBooking::STATUS_CONFIRMED
                ? 'ชำระเงินเรียบร้อย ยืนยันที่พักแล้ว 🎉'
                : 'ได้รับสลิปแล้ว ทีมงานจะตรวจและยืนยันให้เร็วที่สุดครับ',
        );
    }

    public function cancelPreview(Request $request, string $ref): JsonResponse
    {
        $booking = $this->own($request, $ref);

        return $this->success($this->bookings->cancellationPreview($booking) + ['can_cancel' => $booking->canCancel()]);
    }

    public function cancel(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:300'],
            'refund_account' => ['nullable', 'array'],
            'refund_account.bank' => ['nullable', 'string', 'max:60'],
            'refund_account.number' => ['nullable', 'string', 'max:30'],
            'refund_account.name' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $booking = $this->bookings->cancelByCustomer($this->own($request, $ref), $data['reason'] ?? null, $data['refund_account'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $booking->load(['stay', 'review', 'tripBooking']);

        return $this->success(StayPresenter::booking($booking), 'ยกเลิกการจองแล้ว');
    }

    public function refundAccount(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate([
            'bank' => ['required', 'string', 'max:60'],
            'number' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        try {
            $booking = $this->bookings->updateRefundAccount($this->own($request, $ref), $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $booking->load(['stay', 'review', 'tripBooking']);

        return $this->success(StayPresenter::booking($booking), 'บันทึกบัญชีรับเงินคืนแล้ว');
    }

    public function review(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->bookings->review($this->own($request, $ref), (int) $data['rating'], $data['comment'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $booking = $this->own($request, $ref);

        return $this->success(StayPresenter::booking($booking), 'ขอบคุณสำหรับรีวิวครับ 🙏');
    }

    // ── ภายใน ──────────────────────────────────────────────────────

    private function own(Request $request, string $ref): StayBooking
    {
        return StayBooking::with(['stay', 'review', 'tripBooking'])
            ->where('ref', $ref)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function validateBookingInput(Request $request, bool $forQuote = false): array
    {
        $req = $forQuote ? 'nullable' : 'required';

        return $request->validate([
            'room_type_id' => ['required', 'integer'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'units' => ['nullable', 'integer', 'min:1', 'max:'.config('stays.max_units', 10)],
            'guests' => ['required', 'integer', 'min:1', 'max:100'],
            'guest_name' => [$req, 'string', 'max:150'],
            'guest_phone' => [$req, 'string', 'max:30', 'regex:/^[0-9+\-\s()]{8,30}$/'],
            'guest_email' => ['nullable', 'email', 'max:150'],
            'note' => ['nullable', 'string', 'max:1000'],
            'experiences' => ['nullable', 'array', 'max:20'],
            'experiences.*.id' => ['required', 'integer'],
            'experiences.*.people' => ['nullable', 'integer', 'min:1', 'max:50'],
            'trip_booking_ref' => ['nullable', 'string', 'max:40'],
        ], [
            'check_out.after' => 'วันออกต้องอยู่หลังวันเข้าพัก',
            'guest_phone.regex' => 'เบอร์โทรไม่ถูกต้อง',
            'guest_name.required' => 'กรุณากรอกชื่อผู้เข้าพัก',
            'guest_phone.required' => 'กรุณากรอกเบอร์โทรผู้เข้าพัก',
        ]);
    }

    /** ตัวเลขที่บอกว่าเงินถึงชุมชนจริงเท่าไหร่ — โชว์หัวหน้ารายการที่พัก */
    public static function impact(): array
    {
        return [
            'stays' => Stay::active()->count(),
            'communities' => Stay::active()->whereNotNull('community_name')->distinct()->count('community_name'),
            'paid_to_communities' => round((float) StayPayout::sum('amount'), 2),
            'nights_hosted' => (int) StayBooking::where('status', StayBooking::STATUS_COMPLETED)->sum('nights'),
        ];
    }
}
