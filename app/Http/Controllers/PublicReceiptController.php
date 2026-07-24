<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use App\Services\ReceiptService;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * หน้า Digital Travel Receipt สาธารณะ เข้าผ่าน QR / ลิงก์ /receipt/{token}
 * ไม่ต้องล็อกอิน — โชว์รายละเอียดใบเสร็จเพื่อ "ตรวจสอบความถูกต้อง" + ปุ่มโหลด PDF
 */
class PublicReceiptController extends Controller
{
    public function __construct(private ReceiptService $receipts) {}

    public function show(string $token): View|Response
    {
        $receipt = Receipt::where('verify_token', $token)->first();

        if ($receipt === null) {
            return response()->view('receipts.verify', ['receipt' => null], 404);
        }

        return response()->view('receipts.verify', [
            'receipt' => $receipt,
            'd' => $receipt->snapshot ?? [],
            'kindLabel' => $this->receipts->kindLabel($receipt),
        ]);
    }

    public function pdf(string $token): StreamedResponse|Response
    {
        $receipt = Receipt::where('verify_token', $token)->firstOrFail();

        return $this->receipts->pdf($receipt)->stream($this->receipts->pdfFilename($receipt));
    }
}
