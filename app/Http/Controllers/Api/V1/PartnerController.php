<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\PartnerPayout;
use App\Services\PartnerService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * หน้าของพาร์ทเนอร์เอง — โค้ด ลิงก์ ยอดคอม และประวัติการโอน
 *
 * พาร์ทเนอร์เห็นแค่ใบจองที่ตัวเองขายได้ในระดับ "ทริปไหน วันไหน กี่ที่นั่ง" ไม่มีชื่อ
 * เบอร์โทร หรือข้อมูลผู้เดินทาง — ลูกค้าเป็นของบริษัท
 */
class PartnerController extends Controller
{
    use ApiResponse;

    public function __construct(private PartnerService $partners) {}

    /** ข้อมูลพาร์ทเนอร์ของบัญชีนี้ (null = ไม่ใช่พาร์ทเนอร์) */
    public function show(Request $request): JsonResponse
    {
        $partner = $request->user()->partner;

        if (! $partner) {
            return $this->success(null);
        }

        return $this->success([
            'id' => $partner->id,
            'code' => $partner->code,
            'display_name' => $partner->display_name,
            'status' => $partner->status,
            'commission_type' => $partner->commission_type,
            'commission_rate' => (float) $partner->commission_rate,
            'rate_label' => $partner->rateLabel(),
            'share_url' => $partner->shareUrl(),
            'liff_url' => $partner->liffUrl(),
            'payout_account' => self::maskedAccount($partner),
            'summary' => $this->partners->summary($partner),
            'recent_commissions' => $partner->commissions()->latest('id')->limit(10)->get()
                ->map(fn (PartnerCommission $c) => self::commissionRow($c))->values(),
            'rules' => [
                'ลูกค้าจ่ายเงินเข้าบริษัทโดยตรงเสมอ คุณไม่ต้องรับเงินแทน',
                'คอมคิดจากค่าทริปหลังส่วนลด ไม่รวมค่าบริการเสริมและค่าเช่าอุปกรณ์',
                'คอมอนุมัติหลังวันเดินทางกลับ เมื่อลูกค้าชำระครบแล้ว แล้วโอนรวมเป็นรอบ',
                'ใบจองที่ยกเลิกหรือคืนเงินไม่มีคอม · จองให้ตัวเองไม่นับคอม',
            ],
        ]);
    }

    public function commissions(Request $request): JsonResponse
    {
        $partner = $request->user()->partner;
        abort_unless($partner, 404);

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,approved,paid,void'],
        ]);

        $page = $partner->commissions()
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(20);

        return $this->paginated($page->through(fn (PartnerCommission $c) => self::commissionRow($c)));
    }

    public function payouts(Request $request): JsonResponse
    {
        $partner = $request->user()->partner;
        abort_unless($partner, 404);

        $page = $partner->payouts()->latest('paid_at')->paginate(20);

        return $this->paginated($page->through(fn (PartnerPayout $p) => [
            'id' => $p->id,
            'amount' => (float) $p->amount,
            'commission_count' => $p->commission_count,
            'paid_at' => $p->paid_at?->toISOString(),
            'reference' => $p->reference,
        ]));
    }

    public function updatePayoutAccount(Request $request): JsonResponse
    {
        $partner = $request->user()->partner;
        abort_unless($partner, 404);

        $data = $request->validate([
            'bank' => ['required', 'string', 'max:60'],
            'number' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:100'],
        ]);

        $partner = $this->partners->updatePayoutAccount($partner, $data);

        return $this->success(['payout_account' => self::maskedAccount($partner)], 'บันทึกบัญชีรับเงินแล้ว');
    }

    /** หน้าจองเช็คโค้ดก่อนส่ง — บอกชื่อพาร์ทเนอร์ให้ลูกค้ามั่นใจว่าพิมพ์ถูก */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:30']]);
        $partner = Partner::findByCode($data['code']);

        if (! $partner || ! $partner->isActive()) {
            return $this->success(['valid' => false, 'code' => Partner::normalizeCode($data['code'])]);
        }

        return $this->success([
            'valid' => true,
            'code' => $partner->code,
            'display_name' => $partner->display_name,
        ]);
    }

    public static function commissionRow(PartnerCommission $c): array
    {
        return [
            'id' => $c->id,
            'booking_ref' => $c->booking_ref,
            'trip_title' => $c->trip_title,
            'departure_date' => $c->departure_date?->toDateString(),
            'type' => $c->type,
            'status' => $c->status,
            'seats' => $c->seats,
            'base_amount' => (float) $c->base_amount,
            'rate_label' => Partner::formatRate($c->rate_type, (float) $c->rate),
            'amount' => (float) $c->amount,
            'approved_at' => $c->approved_at?->toISOString(),
            'void_reason' => $c->void_reason,
            'payout_id' => $c->payout_id,
            'created_at' => $c->created_at?->toISOString(),
        ];
    }

    private static function maskedAccount(Partner $partner): ?array
    {
        $account = $partner->payout_account;

        if (! is_array($account) || blank($account['number'] ?? null)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $account['number']);

        return [
            'bank' => $account['bank'] ?? '',
            'number_masked' => 'xxx-'.substr($digits, -4),
            'name' => $account['name'] ?? '',
        ];
    }
}
