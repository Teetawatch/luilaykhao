<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\Receipt;
use App\Services\ReceiptService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ใบเสร็จของการจอง (Digital Travel Receipt) สำหรับแอป
 *
 * เอกสารตัวจริงอยู่บนเว็บอยู่แล้ว — หน้าตรวจสอบ /receipt/{token} และ PDF
 * /receipt/{token}/pdf ซึ่งเปิดได้โดยไม่ต้องล็อกอิน (ลูกค้าส่งต่อให้ฝ่ายบัญชี
 * ของบริษัทตัวเองได้) ตรงนี้จึงไม่ได้ทำเอกสารซ้ำ แต่เป็นทางที่แอปหา "ลิงก์
 * ของใบไหน" เจอ โดยยังต้องพิสูจน์สิทธิ์ก่อนได้ token ไป
 */
class ReceiptController extends Controller
{
    use ApiResponse;

    public function __construct(private ReceiptService $receipts) {}

    /**
     * ใบเสร็จทั้งหมดของการจองหนึ่งใบ (มัดจำ/ยอดคงเหลือ/เต็มจำนวน ออกคนละใบ)
     *
     * ผู้จองกับทีมงานเห็นใบรวม พร้อมใบแยกรายบุคคลของทุกคนซ้อนอยู่ใน
     * `personal` (ไว้ส่งต่อให้เพื่อน) เพื่อนร่วมเดินทางที่ผูกบัญชีไว้เห็น
     * เฉพาะใบในชื่อตัวเอง — ใบรวมมีชื่อผู้ชำระกับยอดของทั้งคณะ และ token
     * ในนี้เปิดดูได้โดยไม่ต้องล็อกอิน ใครถือก็เปิดได้ จึงไม่แจกกว้างกว่าที่จำเป็น
     */
    public function index(Request $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        $user = $request->user();
        $isTeam = $user->hasAnyRole(['admin', 'operator']);

        if ($isTeam || $booking->user_id === $user->id) {
            $receipts = Receipt::where('booking_id', $booking->id)
                ->whereNull('parent_id')
                ->with('personalReceipts')
                ->orderBy('issued_at')
                ->get()
                ->map(fn (Receipt $receipt) => $this->format($receipt) + [
                    'personal' => $receipt->personalReceipts
                        ->map(fn (Receipt $personal) => $this->format($personal))
                        ->values(),
                ]);

            return $this->success($receipts);
        }

        $member = BookingMember::where('booking_id', $booking->id)
            ->where('user_id', $user->id)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->first();

        if ($member === null) {
            return $this->error('ใบเสร็จเปิดดูได้เฉพาะผู้จองครับ', 403);
        }

        $receipts = $member->passenger_id === null
            ? collect()
            : Receipt::where('booking_id', $booking->id)
                ->where('passenger_id', $member->passenger_id)
                ->orderBy('issued_at')
                ->get()
                ->map(fn (Receipt $receipt) => $this->format($receipt));

        return $this->success($receipts);
    }

    /** @return array<string, mixed> */
    private function format(Receipt $receipt): array
    {
        $verifyUrl = $this->receipts->verifyUrl($receipt);

        return [
            'receipt_no' => $receipt->receipt_no,
            'kind' => $receipt->kind,
            'kind_label' => $this->receipts->kindLabel($receipt),
            'amount' => (float) $receipt->amount,
            'currency' => $receipt->currency,
            'status' => $receipt->status,
            'issued_at' => $receipt->issued_at?->toISOString(),
            'verify_url' => $verifyUrl,
            'pdf_url' => $verifyUrl.'/pdf',
            'is_personal' => $receipt->isPersonal(),
            // ใบแยกรายบุคคลออกในชื่อผู้เดินทาง — ใบรวมเป็นชื่อผู้จองเสมอ
            'holder_name' => data_get($receipt->snapshot, 'customer.name'),
            'parent_receipt_no' => data_get($receipt->snapshot, 'personal.parent_receipt_no'),
        ];
    }
}
