<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\CustomerIntake;
use App\Models\Payment;
use App\Services\BeamPaymentService;
use App\Services\IntakeBookingService;
use App\Services\PaymentNotAvailableException;
use App\Services\PromptPayService;
use App\Services\SlipChargeService;
use App\Support\AppLinks;
use App\Support\PaymentGateway;
use App\Support\PaymentQuote;
use App\Support\TermsConsent;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ลูกค้ากดจองและจ่ายเงินเองจากลิงก์กลุ่ม — ไม่ต้องรอทีมงานส่ง QR ในแชท
 *
 *   POST /g/{token}/book   เปิดใบจองจากข้อมูลทั้งกลุ่ม (pending, หน้าต่างชำระเงินเท่าแอป)
 *   GET  /g/{token}/pay    เลือกจ่ายเต็ม/มัดจำ + QR + แนบสลิป · จ่ายแล้วกลายเป็นหน้า "จองสำเร็จ"
 *   POST /g/{token}/pay    รับสลิป ผ่าน SlipChargeService ตัวเดียวกับแอป
 *
 * ลิงก์กลุ่มอยู่ในแชทกลุ่ม ใครก็เปิดได้ หน้านี้จึงแสดงได้แค่สิ่งที่ทุกคนในกลุ่มควร
 * เห็นอยู่แล้ว — ยอดเงิน เลขที่จอง ชื่อเล่น ห้ามมีเบอร์ เลขบัตร หรือลิงก์เปิดบัญชี
 * (ลิงก์นั้นไปทาง SMS เข้าเบอร์คนจองเท่านั้น)
 */
class PublicIntakeBookingController extends Controller
{
    public function __construct(
        private readonly IntakeBookingService $intakeBookings,
        private readonly PromptPayService $promptPay,
        private readonly BeamPaymentService $beam,
    ) {}

    public function book(Request $request, string $token): RedirectResponse
    {
        $intake = $this->resolveIntake($token);

        $request->validate([
            'accepted_terms' => ['accepted'],
        ], [
            'accepted_terms.accepted' => 'กรุณาอ่านและกดยอมรับเงื่อนไขการจองก่อน',
        ]);

        try {
            // ช่องทางเป็นของฝั่งเรา ไม่ใช่ค่าที่ฟอร์มส่งมา — หน้านี้คือเว็บเสมอ
            $request->merge(['consent_channel' => 'web']);
            $this->intakeBookings->book($intake, TermsConsent::fromRequest($request));
        } catch (\Exception $e) {
            return redirect()
                ->route('public.intake.group.show', $intake->token)
                ->withErrors(['book' => $e->getMessage()], 'book');
        }

        return redirect()->route('public.intake.pay.show', $intake->token);
    }

    public function show(Request $request, string $token): View|RedirectResponse
    {
        $intake = $this->resolveIntake($token);
        $booking = $this->currentBooking($intake);

        if (! $booking) {
            return redirect()->route('public.intake.group.show', $intake->token);
        }

        $common = [
            'intake' => $intake,
            'booking' => $booking,
            'schedule' => $booking->schedule,
            'trip' => $booking->schedule?->trip,
            'people' => $intake->people()->get()->map->publicLabel()->all(),
        ];

        if ($booking->status === 'confirmed') {
            // จ่ายผ่านเกตเวย์หรือแอดมินอนุมัติสลิปทีหลัง ไม่ได้ผ่าน pay() ของเรา —
            // หน้านี้คือจุดแรกที่รู้ว่าจ่ายแล้ว (ใบที่ทีมงานเปิดได้ SMS ไปตั้งแต่ตอนเปิด)
            if ($intake->wasSelfBooked()) {
                $this->intakeBookings->afterPaid($booking);
            }

            return view('intake.booked', $common + [
                'hasApp' => AppLinks::hasApp($booking->user),
                'iosUrl' => AppLinks::ios(),
                'androidUrl' => AppLinks::android(),
                // เบอร์ปิดไว้เหลือ 4 ตัวท้าย — หน้านี้อยู่ในแชทกลุ่ม แต่คนจองต้องรู้ว่า
                // SMS เปิดบัญชีไปที่เบอร์ไหน
                'phoneTail' => mb_substr(preg_replace('/\D/', '', (string) $booking->user?->phone), -4),
                'isShadow' => (bool) $booking->user?->is_shadow,
                'accountEmail' => $booking->user && ! $booking->user->hasPlaceholderEmail()
                    ? $this->maskEmail($booking->user->email)
                    : null,
            ]);
        }

        if ($booking->slip_ocr_status !== null) {
            return view('intake.pay', $common + ['state' => 'review']);
        }

        $deadline = $this->intakeBookings->paymentDeadline($booking);
        if ($deadline && $deadline->isPast()) {
            return view('intake.pay', $common + ['state' => 'expired']);
        }

        $quote = PaymentQuote::forBooking($booking);
        $plans = $this->plans($quote);
        $plan = $this->pickPlan($request->query('plan'), $booking, $plans);

        return view('intake.pay', $common + [
            'state' => 'pay',
            'deadline' => $deadline,
            'plans' => $plans,
            'plan' => $plan,
            'qrByPlan' => PaymentGateway::isBeam()
                ? []
                : collect($plans)->map(fn (array $row) => $this->qrFor($row['amount']))->all(),
            'beamPayment' => PaymentGateway::isBeam() ? $this->beamChargeFor($booking, $plan) : null,
        ]);
    }

    public function pay(Request $request, string $token, SlipChargeService $slipCharge): RedirectResponse
    {
        $intake = $this->resolveIntake($token);

        $validated = $request->validate([
            'payment_type' => ['required', Rule::in(['full', 'deposit'])],
            'slip_image' => ['required', 'image', 'max:5120'],
            'payment_method' => ['nullable', Rule::in(['promptpay', 'mobile_banking'])],
        ], [
            'slip_image.required' => 'กรุณาแนบสลิปการโอนเงิน',
            'slip_image.image' => 'ไฟล์สลิปต้องเป็นรูปภาพ',
            'slip_image.max' => 'รูปสลิปต้องมีขนาดไม่เกิน 5MB',
        ]);

        $booking = $this->currentBooking($intake);
        $back = redirect()->route('public.intake.pay.show', $intake->token);

        if (! $booking || $booking->status !== 'pending') {
            return $back;
        }

        if ($booking->slip_ocr_status !== null) {
            return $back->withErrors(['slip_image' => 'ได้รับสลิปของการจองนี้แล้ว ทีมงานกำลังตรวจสอบอยู่']);
        }

        $deadline = $this->intakeBookings->paymentDeadline($booking);
        if ($deadline && $deadline->isPast()) {
            return $back;
        }

        try {
            $result = $slipCharge->charge($booking, $validated['payment_type'], [
                'payment_method' => $validated['payment_method'] ?? 'promptpay',
                'slip' => $request->file('slip_image'),
            ]);
        } catch (PaymentNotAvailableException $e) {
            return $back->withErrors(['slip_image' => $e->getMessage()]);
        } catch (ValidationException $e) {
            return $back->withErrors(['slip_image' => $e->validator->errors()->first()]);
        } catch (\Exception $e) {
            Log::error('Intake self-booking payment failed', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);

            return $back->withErrors(['slip_image' => 'บันทึกการชำระเงินไม่สำเร็จ กรุณาลองใหม่ หรือทักทีมงาน']);
        }

        if ($result['status'] === 'confirmed') {
            $this->intakeBookings->afterPaid($result['booking']);
        }

        return $back;
    }

    /**
     * ใบจองที่หน้านี้ต้องดูแล — ใบที่ยังรอชำระหรือจ่ายแล้วเท่านั้น
     *
     * ใบที่ถูกยกเลิกไปแล้ว (หมดเวลาชำระ) ไม่ใช่ของหน้านี้อีก ลูกค้าต้องกลับไปกด
     * จองใหม่จากหน้ากลุ่ม
     */
    private function currentBooking(CustomerIntake $intake): ?Booking
    {
        if (! $intake->booking_id) {
            return null;
        }

        $booking = Booking::with(['schedule.trip', 'passengers', 'seats', 'user'])->find($intake->booking_id);

        return $booking && in_array($booking->status, ['pending', 'confirmed'], true) ? $booking : null;
    }

    /**
     * ตัวเลือกที่ลูกค้าเห็น — จ่ายเต็มหรือมัดจำเท่านั้น (ผ่อน/แบ่งจ่ายต้องตั้งค่าเพิ่ม
     * ซึ่งเกินกว่าหน้าที่ต้องจบในไม่กี่นาทีนี้ ทำในแอปทีหลังได้)
     *
     * ยอดทุกตัวมาจาก PaymentQuote ที่เดียว ไม่คิดเองซ้ำ
     *
     * @param  array<string, mixed>  $quote
     * @return array<string, array<string, mixed>>
     */
    private function plans(array $quote): array
    {
        $plans = [
            'full' => [
                'key' => 'full',
                'label' => 'ชำระเต็มจำนวน',
                'amount' => (float) $quote['full']['amount'],
                'note' => 'จ่ายครั้งเดียวจบ ไม่ต้องจำกำหนดชำระ',
            ],
        ];

        $deposit = $quote['deposit'];
        if ($deposit['available'] ?? false) {
            $dueAt = filled($deposit['balance_due_at'] ?? null)
                ? CarbonImmutable::parse($deposit['balance_due_at'])->timezone('Asia/Bangkok')
                : null;

            $plans['deposit'] = [
                'key' => 'deposit',
                'label' => 'จ่ายมัดจำก่อน',
                'amount' => (float) $deposit['amount'],
                'note' => 'ส่วนที่เหลือ ฿'.number_format((float) $deposit['balance'])
                    .($dueAt ? ' ชำระภายใน '.ThaiDate::full($dueAt) : ' ชำระก่อนเดินทาง'),
            ];
        }

        return $plans;
    }

    /**
     * แผนที่เปิดหน้ามา — ใช้แผนที่บันทึกไว้แล้วก่อน (ลูกค้าเลือกมัดจำแล้วกลับมาหน้านี้
     * ต้องเจอมัดจำ ไม่ใช่ถูกดีดกลับไปจ่ายเต็ม) แล้วค่อยดู ?plan= ที่ลูกค้ากดเลือก
     *
     * @param  array<string, mixed>  $plans
     */
    private function pickPlan(mixed $requested, Booking $booking, array $plans): string
    {
        if (is_string($requested) && isset($plans[$requested])) {
            return $requested;
        }

        return isset($plans[$booking->payment_type]) ? (string) $booking->payment_type : 'full';
    }

    /**
     * QR ของเกตเวย์ — null เมื่อเกตเวย์ล่ม ล้มแล้วต้องไม่ทำให้หน้าพัง ลูกค้ากำลังจะ
     * จ่ายเงิน หน้า error แปลว่าเราไม่ได้เงิน (ตรรกะเดียวกับ PublicPaymentController)
     */
    private function beamChargeFor(Booking $booking, string $plan): ?Payment
    {
        try {
            return $this->beam->ensureCharge(
                $booking,
                $plan === 'deposit' ? Payment::PURPOSE_DEPOSIT : Payment::PURPOSE_FULL,
                'QR_PROMPT_PAY',
            );
        } catch (\Exception $e) {
            Log::error('Intake pay page could not create a Beam charge', [
                'booking_ref' => $booking->booking_ref,
                'plan' => $plan,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function qrFor(float $amount): string
    {
        return $this->promptPay->qrDataUri(
            $this->promptPay->buildPayload((string) config('payment.promptpay_id'), $amount),
        );
    }

    /** a••••@gmail.com — พอให้เจ้าตัวจำได้ว่าใช้อีเมลไหน โดยไม่เผยทั้งอีเมลในแชทกลุ่ม */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('•', max(3, mb_strlen($local) - 1)).'@'.$domain;
    }

    private function resolveIntake(string $token): CustomerIntake
    {
        $intake = CustomerIntake::with('schedule.trip')->where('token', $token)->first();

        if (! $intake) {
            throw new NotFoundHttpException('ลิงก์นี้ใช้ไม่ได้แล้ว');
        }

        return $intake;
    }
}
