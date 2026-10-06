<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GiftVoucher;
use App\Models\GiftVoucherTransaction;
use App\Services\GiftVoucherService;
use App\Support\MediaDisk;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * หลังบ้านบัตรของขวัญ — ตรวจสลิป ดูสมุดบัญชีของแต่ละใบ ปรับยอด ยกเลิก ออกบัตรชดเชย
 */
class AdminGiftVoucherController extends Controller
{
    use ApiResponse;

    public function __construct(private GiftVoucherService $vouchers) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,under_review,active,rejected,cancelled'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = GiftVoucher::with(['purchaser:id,name,email,phone', 'owner:id,name,email,phone'])
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['q'] ?? null, function ($q, $term) {
                $code = GiftVoucher::normalizeCode($term);
                $q->where(function ($inner) use ($term, $code) {
                    if ($code !== '') {
                        $inner->where('code', 'like', '%'.$code.'%');
                    }
                    $inner->orWhere('recipient_name', 'like', '%'.$term.'%')
                        ->orWhereHas('purchaser', fn ($u) => $u->where('name', 'like', '%'.$term.'%')
                            ->orWhere('email', 'like', '%'.$term.'%')
                            ->orWhere('phone', 'like', '%'.$term.'%'))
                        ->orWhereHas('owner', fn ($u) => $u->where('name', 'like', '%'.$term.'%')
                            ->orWhere('email', 'like', '%'.$term.'%')
                            ->orWhere('phone', 'like', '%'.$term.'%'));
                });
            })
            // ใบที่รอตรวจขึ้นก่อนเสมอ — เป็นงานที่ค้างอยู่
            ->orderByRaw("CASE WHEN status = 'under_review' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at');

        $page = $query->paginate($data['per_page'] ?? 30);

        $redeemed = (float) GiftVoucherTransaction::where('type', GiftVoucherTransaction::TYPE_REDEEM)->sum('amount');
        $restored = (float) GiftVoucherTransaction::where('type', GiftVoucherTransaction::TYPE_RESTORE)->sum('amount');

        return $this->paginated(
            $page->through(fn (GiftVoucher $v) => $this->row($v)),
            meta: [
                'summary' => [
                    // เงินที่ได้จากการขายบัตรจริง (ไม่รวมบัตรที่ทีมงานออกให้ฟรี)
                    'sold_total' => round((float) GiftVoucher::whereNotNull('paid_at')->where('is_complimentary', false)->sum('amount'), 2),
                    'sold_count' => GiftVoucher::whereNotNull('paid_at')->where('is_complimentary', false)->count(),
                    'complimentary_total' => round((float) GiftVoucher::where('is_complimentary', true)->sum('amount'), 2),
                    // ยอดที่ลูกค้ายังถือไว้ใช้ได้ = หนี้ที่ต้องให้บริการในอนาคต
                    'outstanding_balance' => round((float) GiftVoucher::where('status', GiftVoucher::STATUS_ACTIVE)
                        ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                        ->sum('balance'), 2),
                    'redeemed_total' => round(-$redeemed - $restored, 2),
                    'under_review_count' => GiftVoucher::where('status', GiftVoucher::STATUS_UNDER_REVIEW)->count(),
                ],
            ],
        );
    }

    public function show(int $voucher): JsonResponse
    {
        $model = GiftVoucher::with([
            'purchaser:id,name,email,phone',
            'owner:id,name,email,phone',
            'reviewer:id,name',
            'transactions' => fn ($q) => $q->with(['booking:id,booking_ref,status', 'actor:id,name'])->latest('id'),
        ])->findOrFail($voucher);

        return $this->success($this->row($model, detail: true));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:'.GiftVoucherService::maxAmount()],
            'recipient_name' => ['nullable', 'string', 'max:100'],
            'from_name' => ['nullable', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:300'],
            'design' => ['nullable', 'string', Rule::in((array) config('gift_voucher.designs', []))],
            'note' => ['required', 'string', 'max:300'],
        ], ['note.required' => 'กรุณาระบุเหตุผลที่ออกบัตร (เช่น ชดเชยทริป LLK-...)']);

        $voucher = $this->vouchers->issueComplimentary($request->user(), $data);

        return $this->success($this->row($voucher->fresh(['purchaser', 'owner']), detail: true), 'ออกบัตรของขวัญแล้ว', 201);
    }

    public function approve(Request $request, int $voucher): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);
        $model = GiftVoucher::findOrFail($voucher);

        if (! in_array($model->status, [GiftVoucher::STATUS_UNDER_REVIEW, GiftVoucher::STATUS_REJECTED, GiftVoucher::STATUS_PENDING], true)) {
            return $this->error('บัตรนี้ไม่ได้รอการยืนยันการชำระเงิน', 422);
        }

        // บัตรที่ยังไม่มีสลิปเลย (ลูกค้าโอนแล้วส่งสลิปทางแชท) ต้องบอกเหตุผลไว้ในประวัติ
        if (! $model->slip_path && blank($data['note'] ?? null)) {
            return $this->error('บัตรนี้ยังไม่มีสลิป กรุณาระบุหมายเหตุว่ายืนยันการชำระเงินจากอะไร', 422);
        }

        try {
            $model = $this->vouchers->activate($model, $request->user(), $data['note'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->row($model->fresh(['purchaser', 'owner'])), 'ยืนยันการชำระเงินแล้ว บัตรพร้อมใช้');
    }

    public function reject(Request $request, int $voucher): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:300']], [
            'note.required' => 'กรุณาบอกเหตุผล ลูกค้าจะเห็นข้อความนี้',
        ]);

        try {
            $model = $this->vouchers->reject(GiftVoucher::findOrFail($voucher), $request->user(), $data['note']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->row($model->fresh(['purchaser', 'owner'])), 'แจ้งลูกค้าให้ส่งสลิปใหม่แล้ว');
    }

    public function adjust(Request $request, int $voucher): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0'],
            'note' => ['required', 'string', 'max:300'],
        ]);

        try {
            $model = $this->vouchers->adjust(GiftVoucher::findOrFail($voucher), $request->user(), (float) $data['amount'], $data['note']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->row($model->fresh(['purchaser', 'owner'])), 'ปรับยอดแล้ว');
    }

    public function cancel(Request $request, int $voucher): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:300']]);

        try {
            $model = $this->vouchers->cancelByAdmin(GiftVoucher::findOrFail($voucher), $request->user(), $data['note']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->row($model->fresh(['purchaser', 'owner'])), 'ยกเลิกบัตรแล้ว');
    }

    private function row(GiftVoucher $v, bool $detail = false): array
    {
        $person = fn ($u) => $u ? ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone] : null;

        $row = [
            'id' => $v->id,
            'code' => $v->code,
            'display_code' => $v->displayCode(),
            'amount' => (float) $v->amount,
            'balance' => (float) $v->balance,
            'status' => $v->status,
            'display_status' => $v->displayStatus(),
            'is_complimentary' => (bool) $v->is_complimentary,
            'recipient_name' => $v->recipient_name,
            'from_name' => $v->from_name,
            'purchaser' => $person($v->purchaser),
            'owner' => $person($v->owner),
            'has_slip' => $v->slip_path !== null,
            'slip_ocr_status' => $v->slip_ocr_status,
            'paid_at' => $v->paid_at?->toISOString(),
            'expires_at' => $v->expires_at?->toISOString(),
            'created_at' => $v->created_at?->toISOString(),
        ];

        if (! $detail) {
            return $row;
        }

        $ocr = $v->slip_ocr_result;
        if (is_string($ocr)) {
            $ocr = json_decode($ocr, true);
        }

        return $row + [
            'message' => $v->message,
            'design' => $v->design,
            'payment_ref' => $v->payment_ref,
            'slip_url' => MediaDisk::slipUrl($v->slip_path),
            'slip_ocr' => is_array($ocr) ? [
                'status' => $ocr['status'] ?? null,
                'amount' => $ocr['amount'] ?? null,
                'bank' => $ocr['bank'] ?? null,
                'date' => $ocr['date'] ?? null,
                'time' => $ocr['time'] ?? null,
            ] : null,
            'review_note' => $v->review_note,
            'reviewed_by' => $v->reviewer?->name,
            'reviewed_at' => $v->reviewed_at?->toISOString(),
            'claimed_at' => $v->claimed_at?->toISOString(),
            'share_url' => $v->status === GiftVoucher::STATUS_ACTIVE ? $v->shareUrl() : null,
            'transactions' => $v->relationLoaded('transactions') ? $v->transactions->map(fn (GiftVoucherTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'amount' => (float) $t->amount,
                'balance_after' => (float) $t->balance_after,
                'note' => $t->note,
                'booking_ref' => $t->booking?->booking_ref,
                'booking_status' => $t->booking?->status,
                'actor' => $t->actor?->name,
                'created_at' => $t->created_at?->toISOString(),
            ])->values() : [],
        ];
    }
}
