<?php

namespace App\Services;

use App\Mail\AdminIntakeReadyMail;
use App\Mail\AdminNewBookingMail;
use App\Mail\AdminPaymentReceivedMail;
use App\Mail\BalanceDueReminderMail;
use App\Mail\BalancePaidMail;
use App\Mail\BookingCancelledMail;
use App\Mail\BookingCreatedMail;
use App\Mail\BookingStatusChangedMail;
use App\Mail\DepositPaidMail;
use App\Mail\EmailVerificationMail;
use App\Mail\GiftClaimedMail;
use App\Mail\GiftPurchasedMail;
use App\Mail\GiftVoucherIssuedMail;
use App\Mail\InstallmentDueReminderMail;
use App\Mail\InstallmentPaidMail;
use App\Mail\PassportExpiringMail;
use App\Mail\PassportInfoNeededMail;
use App\Mail\PasswordResetMail;
use App\Mail\PaymentConfirmedMail;
use App\Mail\TripBriefMail;
use App\Mail\TripPostponedMail;
use App\Mail\TripResumedMail;
use App\Mail\TripUnderfilledWarningMail;
use App\Mail\WelcomeRegistrationMail;
use App\Models\Booking;
use App\Models\CustomerIntake;
use App\Models\EmailLog;
use App\Models\GiftVoucher;
use App\Models\InstallmentPayment;
use App\Models\Receipt;
use App\Models\User;
use App\Support\AccountLinks;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailService
{
    /** อีเมลแจ้งคนไม่ครบที่ล้มเหลว ลองส่งใหม่ถึงที่อยู่เดิมได้ไม่เกินกี่ฉบับ */
    public const UNDERFILLED_MAX_ATTEMPTS = 3;

    public function __construct(private ReceiptService $receipts) {}

    /**
     * ออกใบเสร็จให้การจอง (idempotent) แบบไม่ให้ล้มอีเมลถ้าออกใบเสร็จพลาด
     */
    private function issueReceipt(Booking $booking, string $kind, ?float $amount = null): ?Receipt
    {
        try {
            return $this->receipts->issueForBooking($booking, $kind, $amount);
        } catch (\Throwable $e) {
            Log::error('Failed to issue receipt', [
                'booking_ref' => $booking->booking_ref,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * ใบเสร็จแยกรายบุคคลของใบรวม — พลาดก็แค่ไม่มีใบแยก อีเมลยังต้องออก
     *
     * @return EloquentCollection<int, Receipt>
     */
    private function issuePersonalReceipts(Receipt $receipt): EloquentCollection
    {
        try {
            return $this->receipts->issuePersonalReceipts($receipt);
        } catch (\Throwable $e) {
            Log::error('Failed to issue personal receipts', [
                'receipt_no' => $receipt->receipt_no,
                'error' => $e->getMessage(),
            ]);

            return new EloquentCollection;
        }
    }

    /**
     * ส่งอีเมลที่มีใบเสร็จให้ทุกคนในการจอง — ใครได้ใบไหน
     *
     * จองคนเดียวหลายที่นั่งจะมีใบแยกรายบุคคลด้วย: ผู้จองได้ใบรวมแนบไฟล์ +
     * ลิงก์ใบแยกของทุกคน (ส่งต่อให้เพื่อนที่ไม่ได้กรอกอีเมลได้) ส่วนเพื่อนที่
     * กรอกอีเมลไว้ได้ใบในชื่อตัวเองแนบไปแทนใบรวม ซึ่งมีชื่อผู้จองกับยอดของ
     * ทั้งคณะ — เอาไปเบิกบริษัทไม่ได้
     *
     * @param  callable(?Receipt, EloquentCollection<int, Receipt>, ?string): Mailable  $mailableFactory
     */
    private function sendReceiptEmails(Booking $booking, ?Receipt $receipt, callable $mailableFactory): void
    {
        $personal = $receipt ? $this->issuePersonalReceipts($receipt) : new EloquentCollection;

        if ($personal->isEmpty()) {
            $this->sendToCustomerEmails($booking, fn () => $mailableFactory($receipt, new EloquentCollection, null));

            return;
        }

        $emails = $this->customerEmails($booking);
        $passengers = $booking->passengers->sortBy('id')->values();
        $payerEmail = $this->payerEmail($booking, $emails);
        $byPassenger = $personal->keyBy('passenger_id');

        foreach ($emails as $email) {
            $theirs = $passengers->filter(fn ($p) => $this->normaliseEmail($p->email) === $email);
            $own = new EloquentCollection(
                $theirs->map(fn ($p) => $byPassenger->get($p->id))->filter()->values()->all()
            );

            if ($email === $payerEmail || $own->isEmpty()) {
                Mail::to($email)->send($mailableFactory($receipt, $personal, null));

                continue;
            }

            Mail::to($email)->send($mailableFactory($own->first(), $own, $theirs->first()?->name));
        }
    }

    /**
     * อีเมลไหนคือผู้จอง (คนจ่ายเงิน) ในรายชื่อผู้รับ
     *
     * อีเมลบัญชีผู้จองก่อน ถ้าไม่ได้กรอกไว้ในรายชื่อผู้เดินทาง ก็ถือว่าผู้เดินทาง
     * คนแรกคือผู้จอง — ฟอร์มจองให้กรอกตัวเองเป็นคนแรก
     */
    private function payerEmail(Booking $booking, array $emails): ?string
    {
        $userEmail = $this->normaliseEmail($booking->user?->email);
        if ($userEmail !== null && in_array($userEmail, $emails, true)) {
            return $userEmail;
        }

        $first = $booking->passengers->sortBy('id')
            ->map(fn ($p) => $this->normaliseEmail($p->email))
            ->first(fn ($email) => $email !== null && in_array($email, $emails, true));

        return $first ?? ($emails[0] ?? null);
    }

    private function normaliseEmail(mixed $email): ?string
    {
        return blank($email) ? null : strtolower(trim((string) $email));
    }

    /**
     * บอกทีมงานว่ามีลูกค้ากรอกข้อมูลผ่านลิงก์เข้ามารอเปิดการจอง
     *
     * ส่งครั้งเดียวต่อกลุ่ม (ผู้เรียกเป็นคนกันซ้ำด้วย team_notified_at) —
     * กลุ่ม 5 คนที่ทยอยกรอกไม่ควรกลายเป็นเมล 5 ฉบับ
     */
    public function sendAdminIntakeReady(CustomerIntake $intake, string $reason = 'complete'): void
    {
        try {
            $adminEmails = $this->getAdminEmails();
            if (! empty($adminEmails)) {
                // booking ใช้เฉพาะเมลแบบ reopened (บอกว่าใบไหนล้ม) แต่โหลดมาด้วยเลย
                // ไม่งั้นเมลจะไป lazy-load เอาเองตอน render บนคิว
                $intake->loadMissing(['people', 'schedule.trip', 'link', 'booking']);
                Mail::to($adminEmails)->send(new AdminIntakeReadyMail($intake, $reason));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send admin intake email', [
                'intake_id' => $intake->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get admin email addresses for notifications.
     */
    private function getAdminEmails(): array
    {
        return User::role('admin')
            ->whereNotNull('email')
            ->pluck('email')
            ->toArray();
    }

    private function customerEmails(Booking $booking): array
    {
        $booking->loadMissing(['user', 'passengers']);

        $passengerEmails = $booking->passengers
            ->pluck('email')
            ->filter(fn ($email) => $this->isDeliverable($email))
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->unique()
            ->values()
            ->all();

        if (! empty($passengerEmails)) {
            return $passengerEmails;
        }

        return collect([$booking->user?->email])
            ->filter(fn ($email) => $this->isDeliverable($email))
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * ที่อยู่นี้ส่งถึงคนจริงไหม
     *
     * บัญชีที่ทีมงานเปิดใบจองแทนลูกค้าโดยไม่ได้กรอกอีเมล จะได้ที่อยู่ปลอม
     * manual_...@luilaykhao.com ติดตัวไว้ (ดู AdminController::storeBooking)
     * ทุกฉบับที่ยิงไปที่นั่นคือ hard bounce ที่กดคะแนนโดเมนผู้ส่งของเราลง
     * และพาอีเมลที่ส่งถึงลูกค้าจริงเข้าถังขยะไปด้วย — ตัดทิ้งตั้งแต่ต้นทาง
     * ลูกค้ากลุ่มนี้รับข่าวสารทาง SMS แทน (ดู SmsService, SendTripBriefsJob)
     */
    private function isDeliverable(mixed $email): bool
    {
        if (blank($email)) {
            return false;
        }

        $normalised = strtolower(trim((string) $email));

        return ! str_starts_with($normalised, 'manual_')
            || ! str_ends_with($normalised, '@luilaykhao.com');
    }

    private function sendToCustomerEmails(Booking $booking, callable $mailableFactory): void
    {
        foreach ($this->customerEmails($booking) as $email) {
            Mail::to($email)->send($mailableFactory());
        }
    }

    /**
     * Send welcome email to newly registered user.
     *
     * The verification link rides along inside this email rather than going out
     * as a second one — a signup that lands two emails in the same second reads
     * as a glitch, and the one people actually need to act on is this one.
     */
    public function sendWelcomeEmail(User $user, ?string $verifyUrl = null): void
    {
        try {
            Mail::to($user->email)->send(new WelcomeRegistrationMail($user, $verifyUrl));
        } catch (\Throwable $e) {
            Log::error('Failed to send welcome email', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send the "choose a new password" link.
     *
     * Never lets a mail failure bubble: the forgot-password endpoint answers the
     * same way whether or not the address exists, and an exception here would
     * turn a 500 into an account-enumeration oracle.
     */
    public function sendPasswordResetEmail(User $user, string $token): void
    {
        try {
            $expires = (int) config('auth.passwords.users.expire', 60);

            Mail::to($user->email)->send(new PasswordResetMail(
                $user,
                AccountLinks::resetPassword($token, $user->email),
                $expires,
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send password reset email', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send a standalone "verify your email" link — used by the resend button,
     * when the one folded into the welcome email has aged out.
     */
    public function sendEmailVerificationEmail(User $user): void
    {
        try {
            Mail::to($user->email)->send(new EmailVerificationMail(
                $user,
                AccountLinks::verifyEmail($user),
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send email verification email', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ส่ง "ใบเดินทาง" ฉบับอีเมล — คืนค่าว่ามีปลายทางจริงให้ส่งหรือไม่
     *
     * ตัว boolean นี้คือสิ่งที่ SendTripBriefsJob ใช้ตัดสินใจว่าต้องถอยไปใช้ SMS
     * แทนไหม จึงต้องคืน false เมื่อการจองไม่มีอีเมลที่ส่งถึงคนจริงได้เลย
     * (ลูกค้าที่ทีมงานเปิดใบจองแทนให้ส่วนใหญ่อยู่ในกลุ่มนี้)
     */
    public function sendTripBriefEmail(Booking $booking, bool $isUpdate = false): bool
    {
        $emails = $this->customerEmails($booking);

        if (empty($emails)) {
            return false;
        }

        try {
            foreach ($emails as $email) {
                Mail::to($email)->send(new TripBriefMail($booking, $isUpdate));
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send trip brief email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send booking created confirmation to customer + admin notification.
     */
    public function sendBookingCreatedEmail(Booking $booking): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers']);

        try {
            // ของขวัญ: ผู้ซื้อได้อีเมลเฉพาะที่แนบโค้ด+ลิงก์แชร์ แทนอีเมลจองปกติ
            // (ผู้เดินทางเป็นผู้รับที่ยังไม่กรอกอีเมล — ส่งถึงผู้ซื้อโดยตรง)
            if ($booking->is_gift) {
                $this->sendGiftPurchasedEmail($booking);
            } else {
                $this->sendToCustomerEmails($booking, fn () => new BookingCreatedMail($booking));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send booking created email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            // Admin notification
            $adminEmails = $this->getAdminEmails();
            if (! empty($adminEmails)) {
                Mail::to($adminEmails)->send(new AdminNewBookingMail($booking));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send admin new booking email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ขอข้อมูลพาสปอร์ตที่ยังขาดของทริปต่างประเทศ
     *
     * ใช้กับการจองที่เข้ามาจากช่องทางที่ยังไม่มีช่องให้กรอก — แอปรุ่นก่อนหน้าที่
     * ลูกค้าอีกจำนวนหนึ่งยังใช้อยู่ ลิงก์ในอีเมลจึงเป็นทางเดียวที่คนกลุ่มนี้กรอกได้
     * โดยไม่ต้องรออัปเดตแอป
     */
    public function sendPassportInfoNeededEmail(Booking $booking): void
    {
        $booking->loadMissing(['user', 'schedule.trip', 'passengers']);

        if (! $booking->needsPassportInfo()) {
            return;
        }

        try {
            $url = $booking->passportUrl();

            $this->sendToCustomerEmails($booking, fn () => new PassportInfoNeededMail($booking, $url));
        } catch (\Throwable $e) {
            Log::error('Failed to send passport info needed email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * เตือนว่าพาสปอร์ตที่กรอกไว้แล้วจะหมดอายุเร็วกว่าเกณฑ์ 6 เดือน
     *
     * ต่างจาก sendPassportInfoNeededEmail ที่ทวงของที่ยังไม่ได้กรอก — ฉบับนี้
     * ของครบแล้วแต่เล่มใช้ไม่ได้ ต้องบอกให้ทันไปต่อเล่ม
     */
    public function sendPassportExpiringEmail(Booking $booking, int $daysUntilDeparture): void
    {
        $booking->loadMissing(['user', 'schedule.trip', 'passengers']);

        if (app(TravelDocumentService::class)->expiringTooSoon($booking)->isEmpty()) {
            return;
        }

        try {
            $url = $booking->passportUrl();

            $this->sendToCustomerEmails(
                $booking,
                fn () => new PassportExpiringMail($booking, $url, $daysUntilDeparture),
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send passport expiring email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ส่งอีเมลของขวัญให้ผู้ซื้อ — แนบโค้ดและลิงก์แชร์ไว้ส่งต่อให้ผู้รับ
     */
    public function sendGiftPurchasedEmail(Booking $booking): void
    {
        $booking->loadMissing(['user', 'schedule.trip', 'passengers']);

        $email = $booking->user?->email;
        if (! filled($email)) {
            return;
        }

        try {
            Mail::to($email)->send(new GiftPurchasedMail($booking));
        } catch (\Throwable $e) {
            Log::error('Failed to send gift purchased email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * บัตรของขวัญชำระเงินเรียบร้อย — ส่งรหัสและลิงก์ส่งต่อให้ผู้ซื้อ
     */
    public function sendGiftVoucherIssuedEmail(GiftVoucher $voucher): void
    {
        $voucher->loadMissing('purchaser');

        $email = $voucher->purchaser?->email;
        if (! $this->isDeliverable($email) || str_ends_with(strtolower((string) $email), '@social.local')) {
            return;
        }

        try {
            Mail::to($email)->send(new GiftVoucherIssuedMail($voucher));
        } catch (\Throwable $e) {
            Log::error('Failed to send gift voucher email', [
                'voucher_id' => $voucher->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ส่งอีเมลให้ผู้ให้ของขวัญเมื่อผู้รับกดรับแล้ว
     */
    public function sendGiftClaimedEmail(Booking $booking, string $recipientName): void
    {
        $booking->loadMissing(['giftedBy', 'schedule.trip']);

        $email = $booking->giftedBy?->email;
        if (! filled($email)) {
            return;
        }

        try {
            Mail::to($email)->send(new GiftClaimedMail($booking, $recipientName));
        } catch (\Throwable $e) {
            Log::error('Failed to send gift claimed email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send payment confirmed email to customer + admin notification.
     */
    public function sendPaymentConfirmedEmail(Booking $booking, string $paymentType = 'full'): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers', 'installmentPayments']);

        // ใบเสร็จ: งวดแรก = ยอดงวดแรก, เต็มจำนวน = ยอดที่ชำระ
        $kind = $paymentType === 'installment' ? 'installment' : 'full';
        $receipt = $this->issueReceipt($booking, $kind, (float) $booking->paid_amount);

        try {
            // Customer email
            $this->sendReceiptEmails($booking, $receipt, fn ($r, $personal, $name) => new PaymentConfirmedMail($booking, $paymentType, $r, $personal, $name));
        } catch (\Throwable $e) {
            Log::error('Failed to send payment confirmed email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            // Admin notification
            $adminEmails = $this->getAdminEmails();
            if (! empty($adminEmails)) {
                Mail::to($adminEmails)->send(new AdminPaymentReceivedMail($booking, $paymentType));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send admin payment received email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send booking cancellation email to customer.
     */
    public function sendBookingCancelledEmail(Booking $booking, ?string $reason = null): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers']);

        try {
            $this->sendToCustomerEmails($booking, fn () => new BookingCancelledMail($booking, $reason));
        } catch (\Throwable $e) {
            Log::error('Failed to send booking cancelled email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * รอบถูกเลื่อนเพราะเหตุสุดวิสัย — ลิงก์เลือกรอบใหม่ (ForceMajeureService)
     */
    public function sendTripPostponedEmail(Booking $booking): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers']);

        try {
            $this->sendToCustomerEmails($booking, fn () => new TripPostponedMail($booking));
        } catch (\Throwable $e) {
            Log::error('Failed to send trip postponed email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ทีมงานย้อนการเลื่อน — รอบเดินทางตามเดิม (แก้อีเมลเลื่อนรอบที่ส่งไปก่อนหน้า)
     */
    public function sendTripResumedEmail(Booking $booking): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers']);

        try {
            $this->sendToCustomerEmails($booking, fn () => new TripResumedMail($booking));
        } catch (\Throwable $e) {
            Log::error('Failed to send trip resumed email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send booking status changed email (admin-triggered).
     */
    public function sendBookingStatusChangedEmail(Booking $booking, string $newStatus): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers']);

        try {
            $this->sendToCustomerEmails($booking, fn () => new BookingStatusChangedMail($booking, $newStatus));
        } catch (\Throwable $e) {
            Log::error('Failed to send booking status changed email', [
                'booking_ref' => $booking->booking_ref,
                'new_status' => $newStatus,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send installment payment confirmation email.
     */
    public function sendInstallmentPaidEmail(Booking $booking, InstallmentPayment $installment): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers', 'installmentPayments']);

        try {
            $this->sendToCustomerEmails($booking, fn () => new InstallmentPaidMail($booking, $installment));
        } catch (\Throwable $e) {
            Log::error('Failed to send installment paid email', [
                'booking_ref' => $booking->booking_ref,
                'installment_no' => $installment->installment_no,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send deposit-paid confirmation email after the customer pays the deposit.
     */
    public function sendDepositPaidEmail(Booking $booking): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers', 'seats', 'splitShares']);

        // จ่ายแบบแบ่งกลุ่มเก็บ payment_type เป็น deposit — แยกด้วยการมีส่วนแบ่ง
        $kind = $booking->splitShares()->exists() ? 'split' : 'deposit';
        $receipt = $this->issueReceipt($booking, $kind, (float) $booking->deposit_amount);

        try {
            $this->sendReceiptEmails($booking, $receipt, fn ($r, $personal, $name) => new DepositPaidMail($booking, $r, $personal, $name));
        } catch (\Throwable $e) {
            Log::error('Failed to send deposit paid email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $adminEmails = $this->getAdminEmails();
            if (! empty($adminEmails)) {
                Mail::to($adminEmails)->send(new AdminPaymentReceivedMail($booking, 'deposit'));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send admin deposit received email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send installment due reminder email (due_soon | due_today | overdue).
     */
    public function sendInstallmentDueReminderEmail(Booking $booking, InstallmentPayment $installment, string $reminderType): void
    {
        $booking->loadMissing(['user', 'schedule.trip', 'passengers']);
        $booking->ensurePaymentToken();

        try {
            $this->sendToCustomerEmails($booking, fn () => new InstallmentDueReminderMail($booking, $installment, $reminderType));
        } catch (\Throwable $e) {
            Log::error('Failed to send installment due reminder email', [
                'booking_ref' => $booking->booking_ref,
                'installment_no' => $installment->installment_no,
                'reminder_type' => $reminderType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send balance-due reminder email (called by scheduled command).
     */
    public function sendBalanceDueReminderEmail(Booking $booking): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers']);
        $booking->ensurePaymentToken();

        try {
            $this->sendToCustomerEmails($booking, fn () => new BalanceDueReminderMail($booking));
        } catch (\Throwable $e) {
            Log::error('Failed to send balance due reminder email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send an "trip may be cancelled" warning email — the round is close to
     * departure but still below the guaranteed minimum number of booked seats.
     *
     * เรียกซ้ำได้ (job วิ่งทุกชั่วโมงในช่วง D-7 ถึง D-2): ที่อยู่ที่เข้าคิว/ส่งไปแล้วของ
     * รอบนี้จะไม่ถูกส่งซ้ำ ส่วนที่ล้มเหลวจะลองใหม่จนครบ UNDERFILLED_MAX_ATTEMPTS ฉบับ
     *
     * @return string[] ที่อยู่อีเมลที่ส่งถึงได้ของใบจองนี้ (ว่าง = ไม่มีอีเมลให้ส่ง)
     */
    public function sendTripUnderfilledWarningEmail(Booking $booking, int $daysBefore, int $bookedSeats, int $minSeats): array
    {
        $booking->loadMissing(['user', 'schedule.trip', 'passengers', 'pickupPoint']);

        // แถวหลักฐานต่อผู้รับหนึ่งคน (ดู /admin/underfilled-emails) — ใบจองที่ไม่มี
        // อีเมลส่งถึงได้ก็ลงไว้ด้วย ทีมงานจะได้รู้ว่าคนนี้ต้องโทรแจ้งเอง
        $base = [
            'type' => EmailLog::TYPE_UNDERFILLED_WARNING,
            'booking_id' => $booking->id,
            'schedule_id' => $booking->schedule_id,
            'user_id' => $booking->user_id,
            'booking_ref' => $booking->booking_ref,
            'meta' => [
                'trip_title' => $booking->schedule?->trip?->title,
                'departure_date' => $booking->schedule?->departure_date?->toDateString(),
                'days_before' => $daysBefore,
                'booked_seats' => $bookedSeats,
                'min_seats' => $minSeats,
            ],
        ];

        $previous = EmailLog::query()
            ->where('type', EmailLog::TYPE_UNDERFILLED_WARNING)
            ->where('booking_id', $booking->id)
            ->where('schedule_id', $booking->schedule_id)
            ->get(['recipient', 'status']);

        // ข่าวนี้ต้องถึงคนที่จ่ายเงินด้วย ไม่ใช่แค่อีเมลที่กรอกไว้ในช่องผู้เดินทาง
        // (พิมพ์ผิดบ่อย และมักเป็นอีเมลของเพื่อนร่วมทริป)
        $emails = collect($this->customerEmails($booking))
            ->merge(array_filter([$booking->user?->email], fn ($email) => $this->isDeliverable($email)))
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->unique()
            ->values()
            ->all();

        if (empty($emails)) {
            if ($previous->where('status', EmailLog::STATUS_SKIPPED)->isEmpty()) {
                EmailLog::create($base + [
                    'status' => EmailLog::STATUS_SKIPPED,
                    'error_message' => 'ใบจองนี้ไม่มีอีเมลที่ส่งถึงได้',
                ]);
            }

            return [];
        }

        foreach ($emails as $email) {
            $attempts = $previous->where('recipient', $email);

            if ($attempts->contains(fn ($log) => $log->status !== EmailLog::STATUS_FAILED)
                || $attempts->count() >= self::UNDERFILLED_MAX_ATTEMPTS) {
                continue;
            }

            $log = EmailLog::create($base + ['recipient' => $email]);

            try {
                Mail::to($email)->send(
                    (new TripUnderfilledWarningMail($booking, $daysBefore, $bookedSeats, $minSeats))->logAs($log),
                );
            } catch (\Throwable $e) {
                $log->update([
                    'status' => EmailLog::STATUS_FAILED,
                    'failed_at' => now(),
                    'error_message' => mb_substr($e->getMessage(), 0, 1000),
                ]);

                Log::error('Failed to send trip underfilled warning email', [
                    'booking_ref' => $booking->booking_ref,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $emails;
    }

    /**
     * Send balance-paid confirmation email after the customer settles the remaining balance.
     */
    public function sendBalancePaidEmail(Booking $booking): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers']);

        $receipt = $this->issueReceipt($booking, 'balance', (float) $booking->balance_amount);

        try {
            $this->sendReceiptEmails($booking, $receipt, fn ($r, $personal, $name) => new BalancePaidMail($booking, $r, $personal, $name));
        } catch (\Throwable $e) {
            Log::error('Failed to send balance paid email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $adminEmails = $this->getAdminEmails();
            if (! empty($adminEmails)) {
                Mail::to($adminEmails)->send(new AdminPaymentReceivedMail($booking, 'balance'));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send admin balance received email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
