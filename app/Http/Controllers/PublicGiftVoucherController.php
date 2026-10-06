<?php

namespace App\Http\Controllers;

use App\Models\GiftVoucher;
use App\Support\ThaiDate;
use Illuminate\Http\Response;

/**
 * หน้าเว็บบัตรของขวัญสาธารณะ /voucher/{code} — ลิงก์ที่ผู้ซื้อส่งให้ผู้รับ
 *
 * รหัสในลิงก์คือตัวบัตรเอง ผู้ถือลิงก์จึงเห็นได้แค่สิ่งที่ผู้รับควรรู้ (มูลค่า คำอวยพร
 * สถานะ) ไม่มีชื่อบัญชีผู้ซื้อ บัตรที่ยังไม่ได้จ่ายหรือถูกยกเลิกตอบเหมือนไม่มีอยู่
 */
class PublicGiftVoucherController extends Controller
{
    public function show(string $code): Response
    {
        $voucher = GiftVoucher::where('code', GiftVoucher::normalizeCode($code))->first();

        if (! $voucher || $voucher->status !== GiftVoucher::STATUS_ACTIVE) {
            return response()->view('voucher', ['voucher' => null, 'code' => $code], 404);
        }

        return response()->view('voucher', [
            'voucher' => [
                'code' => $voucher->code,
                'display_code' => $voucher->displayCode(),
                'amount' => (float) $voucher->amount,
                'balance' => (float) $voucher->balance,
                'state' => $voucher->displayStatus(),
                'claimed' => $voucher->owner_user_id !== null,
                'recipient_name' => $voucher->recipient_name,
                'from_name' => $voucher->from_name,
                'message' => $voucher->message,
                'design' => $voucher->design,
                'expires_label' => $voucher->expires_at
                    ? ThaiDate::full($voucher->expires_at->copy()->setTimezone('Asia/Bangkok'))
                    : null,
            ],
            'code' => $voucher->code,
        ]);
    }
}
