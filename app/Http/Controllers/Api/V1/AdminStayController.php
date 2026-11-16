<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Stay;
use App\Models\StayBooking;
use App\Models\StayExperience;
use App\Models\StayReview;
use App\Models\StayRoomType;
use App\Models\User;
use App\Services\StayAvailabilityService;
use App\Services\StayBookingService;
use App\Services\StayPresenter;
use App\Support\ThaiSlug;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * หลังบ้านที่พักชุมชน — ข้อมูลที่พัก ห้อง กิจกรรม ปฏิทิน ใบจอง และการโอนให้เจ้าของ
 */
class AdminStayController extends Controller
{
    use ApiResponse;

    public function __construct(
        private StayBookingService $bookings,
        private StayAvailabilityService $availability,
    ) {}

    // ── ที่พัก ───────────────────────────────────────────────────────

    public function index(): JsonResponse
    {
        $stays = Stay::withCount([
            'roomTypes',
            'bookings as open_requests_count' => fn ($q) => $q->where('status', StayBooking::STATUS_REQUESTED),
            'bookings as upcoming_count' => fn ($q) => $q->where('status', StayBooking::STATUS_CONFIRMED),
        ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success([
            'stays' => $stays->map(fn (Stay $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'type_label' => $s->typeLabel(),
                'province' => $s->province,
                'community_name' => $s->community_name,
                'status' => $s->status,
                'booking_mode' => $s->booking_mode,
                'commission_percent' => (float) $s->commission_percent,
                'cover_image' => $s->coverUrl(),
                'room_types_count' => $s->room_types_count,
                'open_requests_count' => $s->open_requests_count,
                'upcoming_count' => $s->upcoming_count,
                'pending_payout' => round((float) $this->bookings->payableQuery($s)->sum('host_amount'), 2),
                'rating_avg' => $s->rating_avg !== null ? (float) $s->rating_avg : null,
                'rating_count' => (int) $s->rating_count,
            ])->values()->all(),
            'options' => self::options(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return $this->success($this->full(Stay::findOrFail($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);

        $data['slug'] = ThaiSlug::unique(
            $data['slug'] ?? $data['name'],
            fn (string $slug) => Stay::where('slug', $slug)->exists(),
            'stay',
        );
        $data['commission_percent'] ??= (float) config('stays.default_commission_percent', 12);
        $data['sort_order'] ??= (Stay::max('sort_order') ?? -1) + 1;
        $data['host_token'] = Stay::newHostToken();

        $tripIds = $data['trips'] ?? null;
        unset($data['trips']);

        $stay = Stay::create($data);
        $this->syncTrips($stay, $tripIds);

        return $this->success($this->full($stay->fresh()), 'สร้างที่พักแล้ว', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $stay = Stay::findOrFail($id);
        $data = $this->validated($request, $id);

        if (array_key_exists('slug', $data)) {
            $data['slug'] = ThaiSlug::unique(
                $data['slug'] ?: $stay->name,
                fn (string $slug) => Stay::where('slug', $slug)->where('id', '!=', $id)->exists(),
                'stay',
            );
        }

        $tripIds = $data['trips'] ?? null;
        unset($data['trips']);

        $stay->update($data);
        $this->syncTrips($stay, $tripIds);

        return $this->success($this->full($stay->fresh()), 'บันทึกแล้ว');
    }

    public function destroy(int $id): JsonResponse
    {
        $stay = Stay::findOrFail($id);

        if ($stay->bookings()->exists()) {
            $stay->update(['status' => Stay::STATUS_PAUSED]);

            return $this->success(null, 'ที่พักนี้มีประวัติการจอง จึงพักการขายแทนการลบ');
        }

        $stay->delete();

        return $this->success(null, 'ลบที่พักแล้ว');
    }

    public function regenerateHostToken(int $id): JsonResponse
    {
        $stay = Stay::findOrFail($id);
        $stay->update(['host_token' => Stay::newHostToken()]);

        return $this->success(['host_url' => $stay->fresh()->hostUrl()], 'สร้างลิงก์ใหม่แล้ว ลิงก์เก่าใช้ไม่ได้อีก');
    }

    // ── ห้อง / กิจกรรม ──────────────────────────────────────────────

    public function storeRoom(Request $request, int $id): JsonResponse
    {
        $stay = Stay::findOrFail($id);
        $data = $this->validatedRoom($request, true);
        $data['sort_order'] ??= (int) $stay->roomTypes()->max('sort_order') + 1;
        $room = $stay->roomTypes()->create($data);

        return $this->success(StayPresenter::room($room) + ['is_active' => $room->is_active, 'sort_order' => $room->sort_order], 'เพิ่มห้องแล้ว', 201);
    }

    public function updateRoom(Request $request, int $room): JsonResponse
    {
        $model = StayRoomType::findOrFail($room);
        $model->update($this->validatedRoom($request, false));

        return $this->success(StayPresenter::room($model) + ['is_active' => $model->is_active, 'sort_order' => $model->sort_order], 'บันทึกแล้ว');
    }

    public function destroyRoom(int $room): JsonResponse
    {
        $model = StayRoomType::findOrFail($room);

        if (StayBooking::where('room_type_id', $model->id)->exists()) {
            $model->update(['is_active' => false]);

            return $this->success(null, 'ห้องนี้มีประวัติการจอง จึงปิดการขายแทนการลบ');
        }

        $model->delete();

        return $this->success(null, 'ลบห้องแล้ว');
    }

    public function storeExperience(Request $request, int $id): JsonResponse
    {
        $stay = Stay::findOrFail($id);
        $data = $this->validatedExperience($request, true);
        $data['sort_order'] ??= (int) $stay->experiences()->max('sort_order') + 1;
        $experience = $stay->experiences()->create($data);

        return $this->success(StayPresenter::experience($experience) + ['is_active' => $experience->is_active], 'เพิ่มกิจกรรมแล้ว', 201);
    }

    public function updateExperience(Request $request, int $experience): JsonResponse
    {
        $model = StayExperience::findOrFail($experience);
        $model->update($this->validatedExperience($request, false));

        return $this->success(StayPresenter::experience($model) + ['is_active' => $model->is_active], 'บันทึกแล้ว');
    }

    public function destroyExperience(int $experience): JsonResponse
    {
        StayExperience::findOrFail($experience)->delete();

        return $this->success(null, 'ลบกิจกรรมแล้ว');
    }

    // ── ปฏิทิน ──────────────────────────────────────────────────────

    public function calendar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        return $this->success(StayHostController::monthCalendar($this->availability, Stay::findOrFail($id), $data['month'] ?? null));
    }

    public function setNights(Request $request, int $id): JsonResponse
    {
        $stay = Stay::findOrFail($id);
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
                $room, $data['from'], $data['to'],
                isset($data['closed_units']) ? (int) $data['closed_units'] : null,
                ! empty($data['clear_price']) ? null : (isset($data['price']) ? (float) $data['price'] : false),
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(['nights' => $count], 'บันทึกแล้ว');
    }

    // ── ใบจอง ───────────────────────────────────────────────────────

    public function bookings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(array_merge(StayBooking::STATUSES, ['refund_pending']))],
            'stay_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = StayBooking::with(['stay:id,name,slug', 'user:id,name,email,phone'])
            ->when($data['status'] ?? null, fn ($q, $s) => $s === 'refund_pending'
                ? $q->where('refund_status', StayBooking::REFUND_PENDING)
                : $q->where('status', $s))
            ->when($data['stay_id'] ?? null, fn ($q, $id) => $q->where('stay_id', $id))
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('ref', 'like', '%'.$term.'%')
                ->orWhere('guest_name', 'like', '%'.$term.'%')
                ->orWhere('guest_phone', 'like', '%'.$term.'%')))
            // งานที่ต้องทำก่อน: คำขอใหม่ สลิปรอตรวจ
            ->orderByRaw("CASE status WHEN 'requested' THEN 0 WHEN 'payment_review' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->paginate($data['per_page'] ?? 30);

        $counts = StayBooking::query()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $counts['refund_pending'] = StayBooking::where('refund_status', StayBooking::REFUND_PENDING)->count();

        return $this->paginated(
            $page->through(fn (StayBooking $b) => StayPresenter::adminBooking($b)),
            meta: ['counts' => $counts],
        );
    }

    public function showBooking(string $ref): JsonResponse
    {
        return $this->success(StayPresenter::adminBooking($this->findBooking($ref), detail: true));
    }

    public function bookingAction(Request $request, string $ref, string $action): JsonResponse
    {
        $booking = $this->findBooking($ref);
        $admin = $request->user();

        try {
            [$booking, $message] = match ($action) {
                'approve' => [
                    $this->bookings->approveRequest($booking, 'staff', $request->filled('total_amount')
                        ? (float) $request->validate(['total_amount' => ['numeric', 'min:1', 'max:10000000']])['total_amount'] : null),
                    'ยืนยันห้องว่างแล้ว แจ้งลูกค้าให้ชำระเงินแล้ว',
                ],
                'decline' => [
                    $this->bookings->declineRequest($booking, 'staff', $request->validate(['reason' => ['nullable', 'string', 'max:300']])['reason'] ?? null),
                    'ปฏิเสธคำขอและแจ้งลูกค้าแล้ว',
                ],
                'confirm-payment' => [$this->bookings->confirmPayment($booking, $admin), 'ยืนยันการชำระเงินแล้ว'],
                'reject-slip' => [
                    $this->bookings->rejectSlip($booking, $admin, $request->validate(['note' => ['required', 'string', 'max:300']], ['note.required' => 'กรุณาบอกเหตุผล ลูกค้าจะเห็นข้อความนี้'])['note']),
                    'แจ้งลูกค้าให้ส่งสลิปใหม่แล้ว',
                ],
                'cancel' => (function () use ($request, $booking) {
                    $data = $request->validate([
                        'reason' => ['required', 'string', 'max:300'],
                        'refund_amount' => ['nullable', 'numeric', 'min:0'],
                    ], ['reason.required' => 'กรุณาบอกเหตุผลที่ยกเลิก']);

                    return [
                        $this->bookings->cancelByStaff($booking, $data['reason'], isset($data['refund_amount']) ? (float) $data['refund_amount'] : null),
                        'ยกเลิกใบจองแล้ว',
                    ];
                })(),
                'refunded' => [
                    $this->bookings->markRefunded($booking, $request->validate(['note' => ['nullable', 'string', 'max:300']])['note'] ?? null),
                    'บันทึกการคืนเงินแล้ว',
                ],
                default => abort(404),
            };
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(StayPresenter::adminBooking($this->findBooking($booking->ref), detail: true), $message);
    }

    public function bookingNote(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate(['admin_note' => ['nullable', 'string', 'max:2000']]);
        $booking = $this->findBooking($ref);
        $booking->update(['admin_note' => $data['admin_note'] ?? null]);

        return $this->success(StayPresenter::adminBooking($booking->fresh(['stay', 'user', 'tripBooking']), detail: true), 'บันทึกแล้ว');
    }

    // ── จ่ายเจ้าของ / รีวิว ─────────────────────────────────────────

    public function payouts(int $id): JsonResponse
    {
        $stay = Stay::findOrFail($id);
        $payable = $this->bookings->payableQuery($stay)->orderBy('check_in')->get();

        return $this->success([
            'pending_amount' => round((float) $payable->sum('host_amount'), 2),
            'payable' => $payable->map(fn (StayBooking $b) => [
                'ref' => $b->ref,
                'guest_name' => $b->guest_name,
                'check_in' => $b->check_in?->toDateString(),
                'check_out' => $b->check_out?->toDateString(),
                'status' => $b->status,
                'total_amount' => (float) $b->total_amount,
                'host_amount' => (float) $b->host_amount,
            ])->all(),
            'history' => $stay->payouts()->with('paidBy:id,name')->latest('paid_at')->limit(50)->get()->map(fn ($p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'booking_count' => (int) $p->booking_count,
                'paid_at' => $p->paid_at?->toISOString(),
                'reference' => $p->reference,
                'note' => $p->note,
                'paid_by' => $p->paidBy?->name,
            ])->all(),
            'payout_account' => $stay->payout_account,
        ]);
    }

    public function payout(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $payout = $this->bookings->payout(Stay::findOrFail($id), $request->user(), $data['reference'] ?? null, $data['note'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(['id' => $payout->id, 'amount' => (float) $payout->amount], 'บันทึกการโอนให้ที่พักแล้ว');
    }

    public function toggleReview(int $review): JsonResponse
    {
        $model = StayReview::findOrFail($review);
        $model->update(['is_hidden' => ! $model->is_hidden]);
        $this->bookings->refreshRating($model->stay_id);

        return $this->success(['is_hidden' => $model->is_hidden], $model->is_hidden ? 'ซ่อนรีวิวแล้ว' : 'แสดงรีวิวแล้ว');
    }

    // ── ภายใน ──────────────────────────────────────────────────────

    private function findBooking(string $ref): StayBooking
    {
        return StayBooking::with(['stay', 'user', 'tripBooking'])->where('ref', $ref)->firstOrFail();
    }

    private function full(Stay $stay): array
    {
        $stay->load(['roomTypes', 'experiences', 'trips:id,title,slug', 'hostUser:id,name,email,phone']);

        return array_merge($stay->makeVisible(['payout_account', 'admin_note'])->only([
            'id', 'name', 'slug', 'type', 'community_name', 'province', 'district', 'address',
            'latitude', 'longitude', 'map_url', 'summary', 'description', 'community_story',
            'highlights', 'amenities', 'house_rules', 'check_in_time', 'check_out_time',
            'cover_image', 'gallery', 'booking_mode', 'cancellation_policy', 'host_user_id',
            'host_name', 'host_phone', 'host_line_id', 'license_type', 'license_no',
            'payout_account', 'status', 'sort_order', 'admin_note',
        ]), [
            'commission_percent' => (float) $stay->commission_percent,
            'community_share_percent' => $stay->communitySharePercent(),
            'host_url' => $stay->hostUrl(),
            'host_user' => $stay->hostUser ? $stay->hostUser->only(['id', 'name', 'email', 'phone']) : null,
            'rooms' => $stay->roomTypes->map(fn (StayRoomType $r) => StayPresenter::room($r) + [
                'is_active' => (bool) $r->is_active,
                'sort_order' => (int) $r->sort_order,
                'photos_raw' => $r->photos ?? [],
            ])->values()->all(),
            'experiences' => $stay->experiences->map(fn (StayExperience $e) => StayPresenter::experience($e) + [
                'is_active' => (bool) $e->is_active,
                'image_raw' => $e->image,
            ])->values()->all(),
            'trips' => $stay->trips->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'slug' => $t->slug,
                'distance_note' => $t->pivot?->distance_note,
            ])->values()->all(),
            'reviews' => $stay->reviews()->latest()->limit(50)->get()->map(fn (StayReview $r) => StayPresenter::review($r) + ['is_hidden' => (bool) $r->is_hidden])->all(),
        ]);
    }

    /** @param  array<int, array{id: int, distance_note?: ?string}>|null  $trips */
    private function syncTrips(Stay $stay, ?array $trips): void
    {
        if ($trips === null) {
            return;
        }

        $stay->trips()->sync(collect($trips)->mapWithKeys(fn ($t) => [
            (int) $t['id'] => ['distance_note' => trim((string) ($t['distance_note'] ?? '')) ?: null],
        ])->all());
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $id): array
    {
        $required = $id === null ? 'required' : 'sometimes';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', Rule::in(array_keys(Stay::TYPES))],
            'community_name' => ['nullable', 'string', 'max:160'],
            'province' => ['nullable', 'string', 'max:80'],
            'district' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'map_url' => ['nullable', 'string', 'max:1000'],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'community_story' => ['nullable', 'string', 'max:20000'],
            'highlights' => ['nullable', 'array', 'max:20'],
            'highlights.*' => ['string', 'max:200'],
            'amenities' => ['nullable', 'array', 'max:40'],
            'amenities.*' => ['string', Rule::in(array_keys((array) config('stays.amenities', [])))],
            'house_rules' => ['nullable', 'string', 'max:5000'],
            'check_in_time' => ['nullable', 'date_format:H:i'],
            'check_out_time' => ['nullable', 'date_format:H:i'],
            'cover_image' => ['nullable', 'string', 'max:2048'],
            'gallery' => ['nullable', 'array', 'max:40'],
            'gallery.*' => ['string', 'max:2048'],
            'booking_mode' => ['nullable', Rule::in([Stay::MODE_REQUEST, Stay::MODE_INSTANT])],
            'cancellation_policy' => ['nullable', Rule::in(array_keys((array) config('stays.policies', [])))],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'host_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'host_name' => ['nullable', 'string', 'max:150'],
            'host_phone' => ['nullable', 'string', 'max:30'],
            'host_line_id' => ['nullable', 'string', 'max:60'],
            'license_type' => ['nullable', Rule::in(array_keys(Stay::LICENSE_TYPES))],
            'license_no' => ['nullable', 'string', 'max:100'],
            'payout_account' => ['nullable', 'array'],
            'payout_account.bank' => ['nullable', 'string', 'max:60'],
            'payout_account.number' => ['nullable', 'string', 'max:30'],
            'payout_account.name' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([Stay::STATUS_DRAFT, Stay::STATUS_ACTIVE, Stay::STATUS_PAUSED])],
            'sort_order' => ['nullable', 'integer'],
            'admin_note' => ['nullable', 'string', 'max:5000'],
            'trips' => ['nullable', 'array', 'max:50'],
            'trips.*.id' => ['required', 'integer', Rule::exists('trips', 'id')],
            'trips.*.distance_note' => ['nullable', 'string', 'max:200'],
        ]);

        // เปิดขายได้เมื่อมีห้องอย่างน้อยหนึ่งแบบ — ไม่งั้นลูกค้าเห็นหน้าเปล่า
        if (($data['status'] ?? null) === Stay::STATUS_ACTIVE) {
            $hasRoom = $id !== null && StayRoomType::where('stay_id', $id)->where('is_active', true)->exists();
            if (! $hasRoom) {
                abort(response()->json([
                    'success' => false, 'data' => null,
                    'message' => 'เพิ่มห้องอย่างน้อยหนึ่งแบบก่อนเปิดขาย',
                ], 422));
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function validatedRoom(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'units' => [$required, 'integer', 'min:1', 'max:1000'],
            'base_guests' => [$required, 'integer', 'min:1', 'max:100'],
            'max_guests' => [$required, 'integer', 'min:1', 'max:100', 'gte:base_guests'],
            'price_per_night' => [$required, 'numeric', 'min:0', 'max:1000000'],
            'weekend_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'extra_guest_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'breakfast_included' => ['nullable', 'boolean'],
            'photos' => ['nullable', 'array', 'max:20'],
            'photos.*' => ['string', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ], ['max_guests.gte' => 'จำนวนคนสูงสุดต้องไม่น้อยกว่าจำนวนคนที่รวมในราคา']);

        if (array_key_exists('extra_guest_price', $data) && $data['extra_guest_price'] === null) {
            $data['extra_guest_price'] = 0;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function validatedExperience(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$required, 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price_per_person' => [$required, 'numeric', 'min:0', 'max:1000000'],
            'duration_label' => ['nullable', 'string', 'max:60'],
            'image' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);
    }

    public static function options(): array
    {
        return [
            'types' => collect(Stay::TYPES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'license_types' => collect(Stay::LICENSE_TYPES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'policies' => collect(array_keys((array) config('stays.policies', [])))->map(fn ($k) => StayPresenter::policy($k))->all(),
            'amenities' => StayPresenter::amenityOptions(),
            'default_commission_percent' => (float) config('stays.default_commission_percent', 12),
            'hosts' => User::query()->whereIn('id', Stay::whereNotNull('host_user_id')->pluck('host_user_id'))->get(['id', 'name', 'phone'])->all(),
        ];
    }
}
