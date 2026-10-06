<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GiftVoucherResource;
use App\Models\GiftVoucher;
use App\Services\GiftVoucherService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * บัตรของขวัญฝั่งลูกค้า — ซื้อ จ่าย ส่งต่อ เพิ่มเข้าบัญชี (การใช้จ่ายอยู่ที่ตอนจอง)
 */
class GiftVoucherController extends Controller
{
    use ApiResponse;

    /** บัตรที่ยังไม่ได้จ่ายค้างได้พร้อมกันไม่เกินนี้ — กันการกดสร้างทิ้งไว้รก */
    private const MAX_UNPAID = 5;

    public function __construct(private GiftVoucherService $vouchers) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // บัตรที่อยู่ในบัญชี — ใช้ได้ก่อน แล้วค่อยบัตรที่ใช้หมด/หมดอายุ
        $wallet = GiftVoucher::where('owner_user_id', $userId)
            ->where('status', GiftVoucher::STATUS_ACTIVE)
            ->orderByDesc('created_at')
            ->get()
            ->sortByDesc(fn (GiftVoucher $v) => $v->isSpendable() ? 1 : 0)
            ->values();

        // บัตรที่ซื้อ — ซื้อให้ตัวเองแล้วจ่ายเรียบร้อยอยู่ใน wallet แล้ว ไม่ซ้ำที่นี่
        $purchased = GiftVoucher::where('purchaser_user_id', $userId)
            ->where('status', '!=', GiftVoucher::STATUS_CANCELLED)
            ->where(fn ($q) => $q->where('status', '!=', GiftVoucher::STATUS_ACTIVE)
                ->orWhereNull('owner_user_id')
                ->orWhere('owner_user_id', '!=', $userId))
            ->orderByDesc('created_at')
            ->get();

        return $this->success([
            'wallet' => GiftVoucherResource::collection($wallet),
            'purchased' => GiftVoucherResource::collection($purchased),
            'config' => [
                'min_amount' => GiftVoucherService::minAmount(),
                'max_amount' => GiftVoucherService::maxAmount(),
                'presets' => array_values((array) config('gift_voucher.presets', [])),
                'designs' => array_values((array) config('gift_voucher.designs', [])),
                'validity_days' => (int) config('gift_voucher.validity_days', 365),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:'.GiftVoucherService::minAmount(), 'max:'.GiftVoucherService::maxAmount()],
            'for_self' => ['nullable', 'boolean'],
            'recipient_name' => ['nullable', 'string', 'max:100'],
            'from_name' => ['nullable', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:300'],
            'design' => ['nullable', 'string', Rule::in((array) config('gift_voucher.designs', []))],
        ], [
            'amount.min' => 'มูลค่าบัตรขั้นต่ำ ฿'.number_format(GiftVoucherService::minAmount()),
            'amount.max' => 'มูลค่าบัตรสูงสุด ฿'.number_format(GiftVoucherService::maxAmount()),
            'amount.integer' => 'มูลค่าบัตรต้องเป็นจำนวนเต็มบาท',
        ]);

        $unpaid = GiftVoucher::where('purchaser_user_id', $request->user()->id)
            ->whereIn('status', [GiftVoucher::STATUS_PENDING, GiftVoucher::STATUS_REJECTED])
            ->count();
        if ($unpaid >= self::MAX_UNPAID) {
            return $this->error('มีบัตรที่ยังไม่ได้ชำระเงินค้างอยู่ '.$unpaid.' ใบ ชำระหรือยกเลิกใบเดิมก่อนนะครับ', 422);
        }

        $voucher = $this->vouchers->create($request->user(), $data);

        return $this->success([
            'voucher' => new GiftVoucherResource($voucher),
            'payment' => $this->vouchers->paymentInfo($voucher),
        ], 'สร้างบัตรของขวัญแล้ว กรุณาชำระเงินเพื่อเปิดใช้บัตร', 201);
    }

    public function show(Request $request, int $voucher): JsonResponse
    {
        return $this->success(new GiftVoucherResource($this->findMine($request, $voucher)));
    }

    public function payment(Request $request, int $voucher): JsonResponse
    {
        $model = $this->findPurchased($request, $voucher);

        if (! in_array($model->status, [GiftVoucher::STATUS_PENDING, GiftVoucher::STATUS_REJECTED], true)) {
            return $this->error('บัตรนี้ไม่ได้อยู่ระหว่างรอชำระเงิน', 422);
        }

        return $this->success($this->vouchers->paymentInfo($model));
    }

    public function uploadSlip(Request $request, int $voucher): JsonResponse
    {
        $request->validate([
            'slip_image' => ['required', 'image', 'max:10240'],
        ], [
            'slip_image.required' => 'กรุณาแนบรูปสลิปการโอนเงิน',
            'slip_image.image' => 'ไฟล์สลิปต้องเป็นรูปภาพ',
        ]);

        $model = $this->findPurchased($request, $voucher);

        try {
            $model = $this->vouchers->submitSlip($model, $request->file('slip_image'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            new GiftVoucherResource($model),
            $model->status === GiftVoucher::STATUS_ACTIVE
                ? 'ชำระเงินเรียบร้อย บัตรของขวัญพร้อมใช้แล้ว 🎁'
                : 'ได้รับสลิปแล้ว ทีมงานกำลังตรวจสอบยอดโอน บัตรจะพร้อมใช้ทันทีที่ตรวจเสร็จครับ',
        );
    }

    public function destroy(Request $request, int $voucher): JsonResponse
    {
        $model = $this->findPurchased($request, $voucher);

        try {
            $model = $this->vouchers->cancelUnpaid($model);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(new GiftVoucherResource($model), 'ยกเลิกบัตรแล้ว');
    }

    /**
     * ดูบัตรจากรหัส — ก่อนกดเพิ่มเข้าบัญชี หรือก่อนใช้ตอนจอง
     *
     * บอกแค่สิ่งที่ผู้ถือรหัสควรรู้ ไม่บอกว่าใครเป็นผู้ซื้อ บัตรที่ยังไม่ได้จ่ายตอบ
     * เหมือนไม่มีอยู่ จะได้เดารหัสจากคำตอบที่ต่างกันไม่ได้
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:30']]);

        $voucher = $this->vouchers->findByCode($request->code);
        $userId = $request->user()->id;

        if (! $voucher || in_array($voucher->status, [GiftVoucher::STATUS_PENDING, GiftVoucher::STATUS_REJECTED, GiftVoucher::STATUS_CANCELLED], true)) {
            return $this->error('ไม่พบบัตรของขวัญนี้ กรุณาตรวจสอบรหัสอีกครั้ง', 404);
        }

        $ownedByOther = $voucher->owner_user_id !== null && $voucher->owner_user_id !== $userId;

        return $this->success([
            'code' => $voucher->code,
            'display_code' => $voucher->displayCode(),
            'amount' => (float) $voucher->amount,
            // บัตรของคนอื่นไม่บอกยอดคงเหลือ — ไม่ใช่เรื่องของผู้ถือรหัสอีกต่อไป
            'balance' => $ownedByOther ? null : (float) $voucher->balance,
            'status' => $voucher->displayStatus(),
            'recipient_name' => $voucher->recipient_name,
            'from_name' => $voucher->from_name,
            'message' => $voucher->message,
            'design' => $voucher->design,
            'expires_at' => $voucher->expires_at?->toISOString(),
            'owned_by_me' => $voucher->owner_user_id === $userId,
            'owned_by_other' => $ownedByOther,
            'usable' => $voucher->isSpendable() && ! $ownedByOther,
        ]);
    }

    public function claim(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:30']]);

        try {
            $voucher = $this->vouchers->claim($request->code, $request->user());
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(new GiftVoucherResource($voucher), 'เพิ่มบัตรของขวัญเข้าบัญชีแล้ว');
    }

    private function findMine(Request $request, int $id): GiftVoucher
    {
        $userId = $request->user()->id;

        return GiftVoucher::whereKey($id)
            ->where(fn ($q) => $q->where('purchaser_user_id', $userId)->orWhere('owner_user_id', $userId))
            ->firstOrFail();
    }

    private function findPurchased(Request $request, int $id): GiftVoucher
    {
        return GiftVoucher::whereKey($id)
            ->where('purchaser_user_id', $request->user()->id)
            ->firstOrFail();
    }
}
