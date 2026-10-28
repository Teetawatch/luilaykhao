<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\BeamPaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * ปลายทางที่ Beam เด้งกลับหลังลูกค้าจ่ายผ่านแอปธนาคาร (returnUrl ของ REDIRECT flow)
 * และหลังจ่าย/กดยกเลิกบนหน้าชำระเงินของ Beam (redirectUrl / cancelUrl ของ Payment Link)
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
    /** บรรทัดสุดท้ายของขั้นตอนบนหน้าจอ ต่างกันตามว่าจ่ายเพื่ออะไร. */
    private const FINAL_STEP = [
        Payment::PURPOSE_FULL => 'ยืนยันที่นั่งของคุณอัตโนมัติ',
        Payment::PURPOSE_DEPOSIT => 'ยืนยันที่นั่งของคุณอัตโนมัติ',
        Payment::PURPOSE_SPLIT => 'ยืนยันที่นั่งของคุณอัตโนมัติ',
        Payment::PURPOSE_INSTALLMENT => 'ยืนยันที่นั่งของคุณอัตโนมัติ',
        Payment::PURPOSE_INSTALLMENT_DUE => 'ตัดงวดที่ชำระให้อัตโนมัติ',
        Payment::PURPOSE_BALANCE => 'บันทึกยอดคงเหลือให้อัตโนมัติ',
        Payment::PURPOSE_SPLIT_SHARE => 'บันทึกส่วนแบ่งของคุณให้อัตโนมัติ',
        Payment::PURPOSE_EXTRA => 'บันทึกยอดเพิ่มเติมให้อัตโนมัติ',
    ];

    public function __invoke(Request $request): View
    {
        $payment = Payment::find($request->query('payment'));

        $bookingRef = $payment?->booking?->booking_ref;

        return view('payment.return', [
            'payment' => $payment,
            'bookingRef' => $bookingRef,
            'finalStep' => self::FINAL_STEP[$payment?->purpose] ?? 'บันทึกยอดที่ชำระให้อัตโนมัติ',
            'pollUrl' => $payment ? route('payment.return.status', ['payment' => $payment->id]) : null,
            // ลูกค้ากด "ยกเลิก" บนหน้าชำระเงินของ Beam — ไม่มีเงินเข้าแน่นอน ไม่ต้องรอผล
            'cancelled' => $payment !== null && $request->boolean('cancelled') && $payment->status !== Payment::STATUS_SUCCEEDED,
            'backUrl' => $this->sameSiteUrl($request->query('back'))
                ?? ($bookingRef ? $this->backUrl($payment, $bookingRef) : null),
            'doneUrl' => $bookingRef ? url('/confirmation/'.$bookingRef) : null,
        ]);
    }

    /**
     * ?back= มาจาก URL ที่เราประกอบเองตอนออกหน้าชำระเงิน แต่ใครก็แก้ query ได้ — รับเฉพาะ
     * ที่อยู่บนโดเมนเรา ไม่งั้นหน้านี้กลายเป็นทางพาลูกค้าไปเว็บปลอม
     */
    private function sameSiteUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $ours = parse_url((string) config('app.url'), PHP_URL_HOST);
        $theirs = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return $ours && $theirs === $ours && in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /**
     * ปุ่ม "กลับไปเลือกวิธีชำระเงิน" — คนที่จ่ายจากเว็บกลับไปหน้าที่ตัวเองมา
     * (คนที่จ่ายจากแอปปิดหน้าต่างนี้แล้วกลับเข้าแอปเอง)
     */
    private function backUrl(Payment $payment, string $bookingRef): string
    {
        return match ($payment->purpose) {
            Payment::PURPOSE_INSTALLMENT_DUE => url('/installment-payment/'.$bookingRef),
            Payment::PURPOSE_FULL,
            Payment::PURPOSE_DEPOSIT,
            Payment::PURPOSE_SPLIT,
            Payment::PURPOSE_INSTALLMENT => url('/payment/'.$bookingRef),
            default => url('/my-bookings'),
        };
    }

    /**
     * สถานะแบบ JSON ให้หน้าข้างบน poll — ตอบเท่าที่จำเป็น ไม่หลุดข้อมูลการจอง
     *
     * ไม่แนบสถานะการจอง: การจองที่จ่ายยอดคงเหลือ/งวดถัดไป/ส่วนแบ่งกลุ่ม เป็น confirmed
     * อยู่ก่อนแล้ว ถ้าส่งออกไปหน้าเว็บจะอ่านว่า "จ่ายสำเร็จ" ทั้งที่เงินยังไม่เข้า
     */
    public function status(Payment $payment, BeamPaymentService $beamPayments): array
    {
        // คนที่ poll หน้านี้คือคนที่เพิ่งกดจ่ายเสร็จและกำลังนั่งดูหน้าจออยู่ — ถาม Beam
        // ให้เลย ไม่ต้องรอ webhook หรือรอ reconcile ที่แตะแถวนี้ตอนค้างครบ 10 นาที
        $payment = $beamPayments->syncForWatcher($payment);

        return [
            'status' => $payment->status,
            'booking_ref' => $payment->booking?->booking_ref,
        ];
    }
}
