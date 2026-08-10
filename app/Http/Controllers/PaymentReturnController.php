<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * ปลายทางที่ Beam เด้งกลับหลังลูกค้าจ่ายผ่านแอปธนาคาร (returnUrl ของ REDIRECT flow)
 *
 * หน้านี้ "ไม่" ตัดสินว่าจ่ายสำเร็จไหม — คนตัดสินคือ webhook เท่านั้น กลับมาถึงตรงนี้
 * แปลว่าลูกค้าออกจากแอปธนาคารแล้ว ซึ่งเกิดขึ้นได้ทั้งตอนจ่ายสำเร็จและตอนกดยกเลิก
 * เราจึงแค่รอสถานะจริง แล้วพาไปหน้าที่ถูกต้อง
 *
 * ไม่ต้องล็อกอิน เพราะบางเบราว์เซอร์บนมือถือกลับมาโดยไม่มี session เดิม — และหน้านี้
 * ไม่เปิดเผยอะไรนอกจากสถานะของ payment ที่ผู้กดถือ id อยู่แล้ว
 */
class PaymentReturnController extends Controller
{
    public function __invoke(Request $request): View
    {
        $payment = Payment::find($request->query('payment'));

        return view('payment.return', [
            'payment' => $payment,
            'bookingRef' => $payment?->booking?->booking_ref,
            'pollUrl' => $payment ? route('payment.return.status', ['payment' => $payment->id]) : null,
        ]);
    }

    /**
     * สถานะแบบ JSON ให้หน้าข้างบน poll — ตอบเท่าที่จำเป็น ไม่หลุดข้อมูลการจอง
     *
     * ไม่แนบสถานะการจอง: การจองที่จ่ายยอดคงเหลือ/งวดถัดไป/ส่วนแบ่งกลุ่ม เป็น confirmed
     * อยู่ก่อนแล้ว ถ้าส่งออกไปหน้าเว็บจะอ่านว่า "จ่ายสำเร็จ" ทั้งที่เงินยังไม่เข้า
     */
    public function status(Payment $payment): array
    {
        return [
            'status' => $payment->status,
            'booking_ref' => $payment->booking?->booking_ref,
        ];
    }
}
