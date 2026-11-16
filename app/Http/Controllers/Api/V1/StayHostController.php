<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Stay;
use App\Models\StayBooking;
use App\Models\StayReview;
use App\Models\StayRoomType;
use App\Services\StayAvailabilityService;
use App\Services\StayBookingService;
use App\Services\StayPresenter;
use App\Traits\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * หน้าของเจ้าของที่พัก — เปิดจากลิงก์ลับ (host_token) ที่ทีมงานส่งทาง LINE
 * ไม่ต้องสมัครหรือลงแอป: เห็นคำขอ ยืนยัน/ปฏิเสธ ปิดห้องรายวัน ดูยอดที่จะได้รับ
 *
 * ลิงก์คือกุญแจ — ทีมงานสร้างใหม่ได้ที่หลังบ้านเมื่อเจ้าของทำหลุด ลิงก์เก่าใช้ไม่ได้ทันที
 */
class StayHostController extends Controller
{
    use ApiResponse;

    public function __construct(
        private StayBookingService $bookings,
        private StayAvailabilityService $availability,
    ) {}

    public function show(Request $request, string $token): JsonResponse
    {
        $stay = $this->stay($token);
        $today = now('Asia/Bangkok')->toDateString();

        $requests = StayBooking::where('stay_id', $stay->id)
            ->where('status', StayBooking::STATUS_REQUESTED)
            ->orderBy('check_in')
            ->get();

        $upcoming = StayBooking::where('stay_id', $stay->id)
            ->whereIn('status', [StayBooking::STATUS_AWAITING_PAYMENT, StayBooking::STATUS_PAYMENT_REVIEW, StayBooking::STATUS_CONFIRMED])
            ->where('check_out', '>=', $today)
            ->orderBy('check_in')
            ->limit(100)
            ->get();

        $recent = StayBooking::where('stay_id', $stay->id)
            ->whereIn('status', [StayBooking::STATUS_COMPLETED, StayBooking::STATUS_CANCELLED])
            ->orderByDesc('check_in')
            ->limit(20)
            ->get();

        $pendingPayout = round((float) $this->bookings->payableQuery($stay)->sum('host_amount'), 2);
        $upcomingEarnings = round((float) StayBooking::where('stay_id', $stay->id)
            ->where('status', StayBooking::STATUS_CONFIRMED)->sum('host_amount'), 2);

        return $this->success([
            'stay' => [
                'id' => $stay->id,
                'name' => $stay->name,
                'slug' => $stay->slug,
                'cover_image' => $stay->coverUrl(),
                'booking_mode' => $stay->booking_mode,
                'community_share_percent' => $stay->communitySharePercent(),
                'check_in_time' => $stay->check_in_time,
                'check_out_time' => $stay->check_out_time,
                'rating_avg' => $stay->rating_avg !== null ? (float) $stay->rating_avg : null,
                'rating_count' => (int) $stay->rating_count,
            ],
            'rooms' => $stay->roomTypes()->get()->map(fn (StayRoomType $r) => StayPresenter::room($r) + ['is_active' => (bool) $r->is_active])->values()->all(),
            'requests' => $requests->map(fn ($b) => StayPresenter::hostBooking($b))->all(),
            'upcoming' => $upcoming->map(fn ($b) => StayPresenter::hostBooking($b))->all(),
            'recent' => $recent->map(fn ($b) => StayPresenter::hostBooking($b))->all(),
            'earnings' => [
                'pending_payout' => $pendingPayout,
                'upcoming' => $upcomingEarnings,
                'paid_total' => round((float) $stay->payouts()->sum('amount'), 2),
                'payouts' => $stay->payouts()->latest('paid_at')->limit(10)->get()->map(fn ($p) => [
                    'amount' => (float) $p->amount,
                    'booking_count' => (int) $p->booking_count,
                    'paid_at' => $p->paid_at?->toISOString(),
                    'reference' => $p->reference,
                ])->all(),
            ],
            'reviews' => $stay->reviews()->where('is_hidden', false)->with('user:id,name,nickname')->latest()->limit(20)->get()
                ->map(fn (StayReview $r) => StayPresenter::review($r))->all(),
        ]);
    }

    public function calendar(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $stay = $this->stay($token);

        return $this->success(self::monthCalendar($this->availability, $stay, $data['month'] ?? null));
    }

    public function setNights(Request $request, string $token): JsonResponse
    {
        $stay = $this->stay($token);
        $data = $request->validate([
            'room_type_id' => ['required', 'integer'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'closed_units' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'clear_price' => ['nullable', 'boolean'],
        ]);

        $room = StayRoomType::where('stay_id', $stay->id)->findOrFail($data['room_type_id']);

        try {
            $count = $this->bookings->setNights(
                $room,
                $data['from'],
                $data['to'],
                isset($data['closed_units']) ? (int) $data['closed_units'] : null,
                ! empty($data['clear_price']) ? null : (isset($data['price']) ? (float) $data['price'] : false),
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(['nights' => $count], 'บันทึกแล้ว');
    }

    public function approve(string $token, string $ref): JsonResponse
    {
        $booking = $this->booking($token, $ref);

        try {
            $booking = $this->bookings->approveRequest($booking, 'host');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(StayPresenter::hostBooking($booking), 'ยืนยันห้องว่างแล้ว ระบบแจ้งแขกให้ชำระเงินแล้วครับ');
    }

    public function decline(Request $request, string $token, string $ref): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $booking = $this->booking($token, $ref);

        try {
            $booking = $this->bookings->declineRequest($booking, 'host', $data['reason'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(StayPresenter::hostBooking($booking), 'แจ้งแขกแล้วว่าห้องไม่ว่าง');
    }

    public function replyReview(Request $request, string $token, int $review): JsonResponse
    {
        $data = $request->validate(['reply' => ['nullable', 'string', 'max:1000']]);
        $stay = $this->stay($token);
        $model = StayReview::where('stay_id', $stay->id)->findOrFail($review);
        $model->update(['host_reply' => trim((string) ($data['reply'] ?? '')) ?: null]);

        return $this->success(StayPresenter::review($model->load('user')), 'บันทึกคำตอบแล้ว');
    }

    /**
     * ปฏิทินห้องว่างของทั้งเดือน — หลังบ้านใช้ตัวเดียวกัน
     */
    public static function monthCalendar(StayAvailabilityService $availability, Stay $stay, ?string $month): array
    {
        $start = $month
            ? CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')
            : CarbonImmutable::parse(now('Asia/Bangkok')->toDateString())->startOfMonth();
        $end = $start->endOfMonth();

        return [
            'month' => $start->format('Y-m'),
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'rooms' => $availability->calendar($stay, $start->toDateString(), $end->toDateString()),
        ];
    }

    private function stay(string $token): Stay
    {
        abort_if(strlen($token) < 20, 404);

        return Stay::where('host_token', $token)->firstOrFail();
    }

    private function booking(string $token, string $ref): StayBooking
    {
        $stay = $this->stay($token);

        return StayBooking::where('stay_id', $stay->id)->where('ref', $ref)->with('stay')->firstOrFail();
    }
}
