<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\PartnerPayout;
use App\Models\User;
use App\Services\PartnerService;
use App\Support\MediaDisk;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * หลังบ้านพาร์ทเนอร์ช่วยขาย — เปิดบัญชี ตั้งอัตรา ดูยอด บันทึกการโอนคอม ผูกใบจอง
 */
class AdminPartnerController extends Controller
{
    use ApiResponse;

    public function __construct(private PartnerService $partners) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:active,paused'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $partners = Partner::with('user:id,name,email,phone')
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['q'] ?? null, function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('code', 'like', '%'.Partner::normalizeCode($term).'%')
                        ->orWhere('display_name', 'like', '%'.$term.'%')
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%'.$term.'%')
                            ->orWhere('phone', 'like', '%'.$term.'%')
                            ->orWhere('email', 'like', '%'.$term.'%'));
                });
            })
            ->orderBy('display_name')
            ->get();

        $rows = $partners->map(fn (Partner $p) => $this->row($p))->values();

        return $this->success([
            'partners' => $rows,
            'summary' => [
                'partners' => $partners->count(),
                'pending' => round((float) $rows->sum('summary.pending'), 2),
                'approved' => round((float) $rows->sum('summary.approved'), 2),
                'paid' => round((float) $rows->sum('summary.paid'), 2),
                'bookings' => (int) $rows->sum('summary.bookings'),
            ],
        ]);
    }

    /** หาบัญชีที่จะเปิดเป็นพาร์ทเนอร์ */
    public function candidates(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $term = $data['q'];

        $users = User::where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')
            ->orWhere('email', 'like', '%'.$term.'%')
            ->orWhere('phone', 'like', '%'.$term.'%'))
            ->with('partner:id,user_id,code')
            ->limit(15)
            ->get(['id', 'name', 'email', 'phone']);

        return $this->success($users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'partner_code' => $u->partner?->code,
        ])->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(creating: true));

        if ($error = $this->rateError($data['commission_type'], (float) $data['commission_rate'])) {
            return $this->error($error, 422);
        }

        try {
            $partner = $this->partners->create($request->user(), $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->row($partner->load('user'), detail: true), 'เปิดบัญชีพาร์ทเนอร์แล้ว', 201);
    }

    public function update(Request $request, int $partner): JsonResponse
    {
        $model = Partner::findOrFail($partner);
        $data = $request->validate($this->rules(creating: false));

        if ($error = $this->rateError($data['commission_type'] ?? $model->commission_type, (float) ($data['commission_rate'] ?? $model->commission_rate))) {
            return $this->error($error, 422);
        }

        try {
            $model = $this->partners->update($model, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->row($model->load('user'), detail: true), 'บันทึกแล้ว');
    }

    public function show(int $partner): JsonResponse
    {
        $model = Partner::with('user:id,name,email,phone')->findOrFail($partner);

        return $this->success($this->row($model, detail: true));
    }

    public function payout(Request $request, int $partner): JsonResponse
    {
        $model = Partner::findOrFail($partner);
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:300'],
            'slip' => ['nullable', 'image', 'max:5120'],
        ]);

        if ($request->hasFile('slip')) {
            $data['slip_path'] = $request->file('slip')->store('slips/partner-payouts/'.date('Y/m'), MediaDisk::slipDisk());
        }

        try {
            $payout = $this->partners->recordPayout($model, $request->user(), $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'payout' => $this->payoutRow($payout),
            'partner' => $this->row($model->fresh('user'), detail: true),
        ], 'บันทึกการโอนคอม ฿'.number_format((float) $payout->amount, 2).' แล้ว');
    }

    public function voidCommission(Request $request, int $commission): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']], [
            'reason.required' => 'กรุณาระบุเหตุผล พาร์ทเนอร์จะเห็นข้อความนี้',
        ]);

        try {
            $model = $this->partners->voidCommission(PartnerCommission::findOrFail($commission), $data['reason']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(PartnerController::commissionRow($model), 'ตัดคอมรายการนี้แล้ว');
    }

    /** ผูก/เปลี่ยน/ถอดพาร์ทเนอร์ของใบจอง (partner_id = null คือถอด) */
    public function assignBooking(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate(['partner_id' => ['nullable', 'integer', 'exists:partners,id']]);
        $booking = Booking::where('booking_ref', $ref)->firstOrFail();
        $partner = filled($data['partner_id'] ?? null) ? Partner::find($data['partner_id']) : null;

        try {
            $booking = $this->partners->assignByAdmin($booking, $partner);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'booking_ref' => $booking->booking_ref,
            'partner' => $partner ? ['id' => $partner->id, 'code' => $partner->code, 'display_name' => $partner->display_name] : null,
            'partner_source' => $booking->partner_source,
        ], $partner ? 'ผูกใบจองกับ '.$partner->display_name.' แล้ว' : 'ถอดพาร์ทเนอร์ออกจากใบจองแล้ว');
    }

    /** % เกิน 50 แทบแน่ใจว่าพิมพ์ผิด (ตั้งใจจะใส่เป็นบาทต่อที่นั่ง) */
    private function rateError(string $type, float $rate): ?string
    {
        return $type === Partner::TYPE_PERCENT && $rate > 50
            ? 'อัตราคอมแบบ % ต้องไม่เกิน 50% — ถ้าตั้งใจจ่ายเป็นบาท เลือกแบบ "บาทต่อที่นั่ง"'
            : null;
    }

    private function rules(bool $creating): array
    {
        $sometimes = $creating ? 'required' : 'sometimes';

        return [
            'user_id' => $creating ? ['required', 'integer', 'exists:users,id'] : ['prohibited'],
            'code' => ['nullable', 'string', 'max:20'],
            'display_name' => [$creating ? 'nullable' : 'sometimes', 'string', 'max:100'],
            'commission_type' => [$sometimes, Rule::in([Partner::TYPE_PERCENT, Partner::TYPE_PER_SEAT])],
            'commission_rate' => [$sometimes, 'numeric', 'min:0', 'max:100000'],
            'status' => $creating ? ['prohibited'] : ['sometimes', Rule::in([Partner::STATUS_ACTIVE, Partner::STATUS_PAUSED])],
            'note' => ['nullable', 'string', 'max:1000'],
            'payout_account' => ['nullable', 'array'],
            'payout_account.bank' => ['nullable', 'string', 'max:60'],
            'payout_account.number' => ['nullable', 'string', 'max:30'],
            'payout_account.name' => ['nullable', 'string', 'max:100'],
        ];
    }

    private function row(Partner $partner, bool $detail = false): array
    {
        $row = [
            'id' => $partner->id,
            'code' => $partner->code,
            'display_name' => $partner->display_name,
            'status' => $partner->status,
            'commission_type' => $partner->commission_type,
            'commission_rate' => (float) $partner->commission_rate,
            'rate_label' => $partner->rateLabel(),
            'user' => $partner->user ? [
                'id' => $partner->user->id,
                'name' => $partner->user->name,
                'email' => $partner->user->email,
                'phone' => $partner->user->phone,
            ] : null,
            'has_payout_account' => $partner->payoutAccountSummary() !== null,
            'summary' => $this->partners->summary($partner),
            'created_at' => $partner->created_at?->toISOString(),
        ];

        if (! $detail) {
            return $row;
        }

        return $row + [
            'share_url' => $partner->shareUrl(),
            'liff_url' => $partner->liffUrl(),
            'note' => $partner->note,
            'payout_account' => $partner->payout_account,
            'commissions' => $partner->commissions()->latest('id')->limit(300)->get()
                ->map(fn (PartnerCommission $c) => PartnerController::commissionRow($c))->values(),
            'payouts' => $partner->payouts()->with('paidBy:id,name')->latest('paid_at')->get()
                ->map(fn (PartnerPayout $p) => $this->payoutRow($p))->values(),
        ];
    }

    private function payoutRow(PartnerPayout $payout): array
    {
        return [
            'id' => $payout->id,
            'amount' => (float) $payout->amount,
            'commission_count' => $payout->commission_count,
            'paid_at' => $payout->paid_at?->toISOString(),
            'reference' => $payout->reference,
            'note' => $payout->note,
            'slip_url' => MediaDisk::slipUrl($payout->slip_path),
            'paid_by' => $payout->paidBy?->name,
        ];
    }
}
