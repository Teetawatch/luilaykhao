<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\GiftVoucher;
use App\Models\GiftVoucherTransaction;
use App\Models\SmartNotification;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * บัตรของขวัญแบบระบุยอดเงิน — ทุกการเปลี่ยนยอดคงเหลือผ่านที่นี่ที่เดียว
 *
 * กติกาเรื่องเงิน:
 * - ซื้อ: โอนพร้อมเพย์ + ส่งสลิป บัตรเปิดใช้ทันทีเมื่อ OCR อ่านได้และยอดตรง
 *   นอกนั้น (อ่านไม่ได้/ยอดไม่ตรง) รอทีมงานอนุมัติ — เข้มกว่าการจองหนึ่งขั้น เพราะ
 *   บัตรคือเงินสด ไม่มีที่นั่งที่ต้องรีบยืนยัน
 * - ใช้: ตอนสร้างการจองเท่านั้น หักออกจากยอดที่ต้องจ่าย (BookingService) ยอดมัดจำ/
 *   งวด/แบ่งจ่ายจึงคิดต่อจากยอดที่เหลือเองโดยไม่ต้องแก้ PaymentQuote
 * - คืน: ใบจองที่ยังไม่จ่ายแล้วถูกยกเลิก/หมดเวลา คืนเต็มจำนวนอัตโนมัติ (BookingObserver)
 *   ใบที่ยืนยันแล้วคืนตอนทีมงานบันทึกคืนเงิน ตามสัดส่วนนโยบายยกเลิก (BookingService)
 */
class GiftVoucherService
{
    public function __construct(
        private SlipOcrService $slipOcr,
        private PromptPayService $promptPay,
        private MailService $mail,
    ) {}

    public static function minAmount(): int
    {
        return (int) config('gift_voucher.min_amount', 300);
    }

    public static function maxAmount(): int
    {
        return (int) config('gift_voucher.max_amount', 50000);
    }

    // ── ซื้อ ─────────────────────────────────────────────────────────

    /**
     * @param  array{amount: int|float, recipient_name?: ?string, from_name?: ?string, message?: ?string, design?: ?string, for_self?: bool}  $data
     */
    public function create(User $purchaser, array $data): GiftVoucher
    {
        $designs = (array) config('gift_voucher.designs', ['forest']);
        $design = in_array($data['design'] ?? null, $designs, true) ? $data['design'] : $designs[0];
        $forSelf = (bool) ($data['for_self'] ?? false);

        return GiftVoucher::create([
            'code' => GiftVoucher::generateCode(),
            'purchaser_user_id' => $purchaser->id,
            // ซื้อให้ตัวเอง = ผูกเข้าบัญชีตั้งแต่แรก ไม่ต้องกรอกรหัสอีกรอบ
            'owner_user_id' => $forSelf ? $purchaser->id : null,
            'amount' => (int) $data['amount'],
            'balance' => 0,
            'status' => GiftVoucher::STATUS_PENDING,
            'recipient_name' => $forSelf ? null : self::clean($data['recipient_name'] ?? null),
            'from_name' => self::clean($data['from_name'] ?? null),
            'message' => self::clean($data['message'] ?? null),
            'design' => $design,
        ]);
    }

    /**
     * ทีมงานออกบัตรให้ลูกค้าโดยไม่มีเงินเข้า (ชดเชยทริปที่มีปัญหา รางวัลกิจกรรม)
     * — ใช้ได้ทันที ไม่นับเป็นยอดขายบัตร
     *
     * @param  array{amount: int|float, recipient_name?: ?string, from_name?: ?string, message?: ?string, design?: ?string, note: string}  $data
     */
    public function issueComplimentary(User $admin, array $data): GiftVoucher
    {
        $designs = (array) config('gift_voucher.designs', ['forest']);

        return DB::transaction(function () use ($admin, $data, $designs) {
            $voucher = GiftVoucher::create([
                'code' => GiftVoucher::generateCode(),
                'purchaser_user_id' => null,
                'amount' => round((float) $data['amount'], 2),
                'balance' => round((float) $data['amount'], 2),
                'status' => GiftVoucher::STATUS_ACTIVE,
                'is_complimentary' => true,
                'recipient_name' => self::clean($data['recipient_name'] ?? null),
                'from_name' => self::clean($data['from_name'] ?? null) ?? 'ทีมงานลุยเลเขา',
                'message' => self::clean($data['message'] ?? null),
                'design' => in_array($data['design'] ?? null, $designs, true) ? $data['design'] : $designs[0],
                'paid_at' => now(),
                'expires_at' => now()->addDays((int) config('gift_voucher.validity_days', 365)),
                'reviewed_by_id' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => self::clean($data['note'] ?? null),
            ]);

            $this->record($voucher, GiftVoucherTransaction::TYPE_PURCHASE, (float) $voucher->amount, null, 'ทีมงานออกบัตร: '.($data['note'] ?? ''), $admin->id);

            return $voucher;
        });
    }

    /** QR พร้อมเพย์และบัญชีโอนของบัตรที่ยังไม่ได้จ่าย — ยอดมาจากหลังบ้านเสมอ */
    public function paymentInfo(GiftVoucher $voucher): array
    {
        $amount = round((float) $voucher->amount, 2);
        $payload = $this->promptPay->buildPayload((string) config('payment.promptpay_id'), $amount);

        return [
            'voucher_id' => $voucher->id,
            'amount' => $amount,
            'qr_payload' => $payload,
            'qr_data_uri' => $this->promptPay->qrDataUri($payload),
            'promptpay_id' => config('payment.promptpay_id_display'),
            'merchant_name' => config('payment.merchant_name'),
            'bank_name' => config('payment.bank_name'),
            'bank_account' => config('payment.bank_account'),
            'bank_holder' => config('payment.bank_holder'),
            'support_phone' => config('payment.support_phone'),
        ];
    }

    /**
     * รับสลิป — เปิดบัตรทันทีเมื่อ OCR ยืนยันยอดได้ นอกนั้นส่งให้ทีมงานตรวจ
     *
     * @throws \Exception สถานะบัตรไม่ได้รอการชำระเงิน
     */
    public function submitSlip(GiftVoucher $voucher, UploadedFile $slip): GiftVoucher
    {
        if (! in_array($voucher->status, [GiftVoucher::STATUS_PENDING, GiftVoucher::STATUS_REJECTED], true)) {
            throw new \Exception(match ($voucher->status) {
                GiftVoucher::STATUS_UNDER_REVIEW => 'ได้รับสลิปของบัตรนี้แล้ว ทีมงานกำลังตรวจสอบอยู่ครับ',
                GiftVoucher::STATUS_ACTIVE => 'บัตรนี้ชำระเงินเรียบร้อยแล้ว',
                default => 'บัตรนี้ถูกยกเลิกแล้ว',
            });
        }

        $path = $slip->store('slips/vouchers/'.date('Y/m'), MediaDisk::slipDisk());

        $claimed = DB::transaction(function () use ($voucher, $path) {
            $locked = GiftVoucher::lockForUpdate()->find($voucher->id);

            // ส่งซ้ำพร้อมกันสองครั้ง — ครั้งหลังต้องไม่ทับสลิปของครั้งแรก
            if (! $locked || ! in_array($locked->status, [GiftVoucher::STATUS_PENDING, GiftVoucher::STATUS_REJECTED], true)) {
                return null;
            }

            $locked->update([
                'slip_path' => $path,
                'slip_ocr_status' => SlipOcrService::STATUS_PENDING,
                'slip_ocr_result' => null,
                'status' => GiftVoucher::STATUS_UNDER_REVIEW,
                'payment_ref' => 'GVPAY-'.strtoupper(uniqid()),
                'review_note' => null,
            ]);

            return $locked;
        });

        if (! $claimed) {
            throw new \Exception('ได้รับสลิปของบัตรนี้แล้ว ทีมงานกำลังตรวจสอบอยู่ครับ');
        }

        $result = $this->slipOcr->verify($path, (float) $claimed->amount);
        $claimed->update([
            'slip_ocr_status' => $result['status'],
            'slip_ocr_result' => $result['raw'] ?? null,
        ]);

        if ($result['status'] === SlipOcrService::STATUS_VERIFIED) {
            return $this->activate($claimed);
        }

        $this->notifyAdminsToReview($claimed, (string) ($result['reason'] ?? 'unknown'));

        if ($claimed->purchaser_user_id) {
            SmartNotification::send(
                $claimed->purchaser_user_id,
                'gift_voucher_review',
                'ได้รับสลิปบัตรของขวัญแล้ว',
                'ทีมงานกำลังตรวจสอบยอดโอน บัตรมูลค่า ฿'.number_format((float) $claimed->amount).' จะพร้อมใช้ทันทีที่ตรวจเสร็จครับ',
                ['voucher_id' => $claimed->id, 'route' => 'gift_voucher'],
            );
        }

        return $claimed->fresh();
    }

    /**
     * เปิดใช้บัตร (เงินเข้าแล้ว) — เรียกซ้ำได้ ไม่เติมยอดซ้ำ
     */
    public function activate(GiftVoucher $voucher, ?User $reviewer = null, ?string $note = null): GiftVoucher
    {
        $activated = DB::transaction(function () use ($voucher, $reviewer, $note) {
            $locked = GiftVoucher::lockForUpdate()->findOrFail($voucher->id);

            if ($locked->status === GiftVoucher::STATUS_ACTIVE) {
                return null;
            }

            if ($locked->status === GiftVoucher::STATUS_CANCELLED) {
                throw new \Exception('บัตรนี้ถูกยกเลิกแล้ว เปิดใช้ไม่ได้');
            }

            $locked->update([
                'status' => GiftVoucher::STATUS_ACTIVE,
                'balance' => $locked->amount,
                'paid_at' => now(),
                'expires_at' => now()->addDays((int) config('gift_voucher.validity_days', 365)),
                'slip_ocr_status' => $reviewer ? SlipOcrService::STATUS_APPROVED : $locked->slip_ocr_status,
                'reviewed_by_id' => $reviewer?->id,
                'reviewed_at' => $reviewer ? now() : null,
                'review_note' => $note,
            ]);

            $this->record($locked, GiftVoucherTransaction::TYPE_PURCHASE, (float) $locked->amount, null, 'ชำระค่าบัตรของขวัญ', $reviewer?->id);

            return $locked;
        });

        if (! $activated) {
            return $voucher->fresh();
        }

        $this->announceActivated($activated);

        return $activated->fresh();
    }

    public function reject(GiftVoucher $voucher, User $reviewer, string $note): GiftVoucher
    {
        $rejected = DB::transaction(function () use ($voucher, $reviewer, $note) {
            $locked = GiftVoucher::lockForUpdate()->findOrFail($voucher->id);

            if ($locked->status !== GiftVoucher::STATUS_UNDER_REVIEW) {
                throw new \Exception('ปฏิเสธได้เฉพาะบัตรที่รอตรวจสลิป');
            }

            $locked->update([
                'status' => GiftVoucher::STATUS_REJECTED,
                'slip_ocr_status' => SlipOcrService::STATUS_REJECTED,
                'reviewed_by_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            return $locked;
        });

        if ($rejected->purchaser_user_id) {
            SmartNotification::send(
                $rejected->purchaser_user_id,
                'gift_voucher_rejected',
                'สลิปบัตรของขวัญยังไม่ผ่าน',
                "ทีมงานตรวจสลิปแล้วยังยืนยันยอดไม่ได้ ({$note}) ส่งสลิปใหม่ได้ที่หน้าบัตรของขวัญ หรือทักทีมงานได้เลยครับ",
                ['voucher_id' => $rejected->id, 'route' => 'gift_voucher'],
            );
        }

        return $rejected->fresh();
    }

    /** ผู้ซื้อเลิกบัตรที่ยังไม่ได้จ่าย — บัตรที่ส่งสลิปแล้วต้องให้ทีมงานจัดการ */
    public function cancelUnpaid(GiftVoucher $voucher): GiftVoucher
    {
        return DB::transaction(function () use ($voucher) {
            $locked = GiftVoucher::lockForUpdate()->findOrFail($voucher->id);

            if (! in_array($locked->status, [GiftVoucher::STATUS_PENDING, GiftVoucher::STATUS_REJECTED], true)) {
                throw new \Exception($locked->status === GiftVoucher::STATUS_UNDER_REVIEW
                    ? 'บัตรนี้ส่งสลิปแล้ว ทีมงานกำลังตรวจสอบ ถ้าต้องการยกเลิกกรุณาทักทีมงานครับ'
                    : 'ยกเลิกได้เฉพาะบัตรที่ยังไม่ได้ชำระเงิน');
            }

            $locked->update(['status' => GiftVoucher::STATUS_CANCELLED]);

            return $locked;
        });
    }

    /**
     * ทีมงานยกเลิกบัตร (เช่น คืนเงินค่าบัตรให้ลูกค้าทางบัญชีธนาคาร) — ยอดคงเหลือเป็นศูนย์
     * ใบจองที่ใช้บัตรไปแล้วไม่ถูกแตะ
     */
    public function cancelByAdmin(GiftVoucher $voucher, User $admin, string $note): GiftVoucher
    {
        return DB::transaction(function () use ($voucher, $admin, $note) {
            $locked = GiftVoucher::lockForUpdate()->findOrFail($voucher->id);

            if ($locked->status === GiftVoucher::STATUS_CANCELLED) {
                throw new \Exception('บัตรนี้ถูกยกเลิกไปแล้ว');
            }

            $balance = round((float) $locked->balance, 2);
            if ($balance > 0) {
                $locked->balance = 0;
                $this->record($locked, GiftVoucherTransaction::TYPE_ADJUST, -$balance, null, 'ยกเลิกบัตร: '.$note, $admin->id);
            }

            $locked->update([
                'status' => GiftVoucher::STATUS_CANCELLED,
                'balance' => 0,
                'reviewed_by_id' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            return $locked->fresh();
        });
    }

    /** ทีมงานปรับยอด (บวก/ลบ) พร้อมเหตุผล — ยอดคงเหลือต้องไม่ติดลบและไม่เกินมูลค่าหน้าบัตร */
    public function adjust(GiftVoucher $voucher, User $admin, float $delta, string $note): GiftVoucher
    {
        return DB::transaction(function () use ($voucher, $admin, $delta, $note) {
            $locked = GiftVoucher::lockForUpdate()->findOrFail($voucher->id);
            $delta = round($delta, 2);

            if ($locked->status !== GiftVoucher::STATUS_ACTIVE) {
                throw new \Exception('ปรับยอดได้เฉพาะบัตรที่ใช้งานอยู่');
            }
            if ($delta == 0.0) {
                throw new \Exception('กรุณาระบุยอดที่ต้องการปรับ');
            }

            $newBalance = round((float) $locked->balance + $delta, 2);
            if ($newBalance < 0) {
                throw new \Exception('ยอดคงเหลือหลังปรับต้องไม่ติดลบ');
            }
            if ($newBalance > (float) $locked->amount) {
                throw new \Exception('ยอดคงเหลือต้องไม่เกินมูลค่าหน้าบัตร ฿'.number_format((float) $locked->amount));
            }

            $locked->balance = $newBalance;
            $locked->save();
            $this->record($locked, GiftVoucherTransaction::TYPE_ADJUST, $delta, null, $note, $admin->id);

            return $locked->fresh();
        });
    }

    // ── ผู้รับ ──────────────────────────────────────────────────────

    public function findByCode(?string $code): ?GiftVoucher
    {
        $normalized = GiftVoucher::normalizeCode($code);

        return $normalized === '' ? null : GiftVoucher::where('code', $normalized)->first();
    }

    /**
     * ผูกบัตรเข้าบัญชี — หลังจากนี้ใช้ได้เฉพาะบัญชีนี้
     *
     * @throws \Exception
     */
    public function claim(string $code, User $user): GiftVoucher
    {
        return DB::transaction(function () use ($code, $user) {
            $voucher = GiftVoucher::where('code', GiftVoucher::normalizeCode($code))->lockForUpdate()->first();

            if (! $voucher || in_array($voucher->status, [GiftVoucher::STATUS_PENDING, GiftVoucher::STATUS_REJECTED, GiftVoucher::STATUS_CANCELLED], true)) {
                throw new \Exception('ไม่พบบัตรของขวัญนี้ กรุณาตรวจสอบรหัสอีกครั้ง');
            }
            if ($voucher->status === GiftVoucher::STATUS_UNDER_REVIEW) {
                throw new \Exception('บัตรนี้กำลังรอทีมงานยืนยันการชำระเงิน ลองใหม่อีกครั้งในภายหลังนะครับ');
            }
            if ($voucher->owner_user_id !== null && $voucher->owner_user_id !== $user->id) {
                throw new \Exception('บัตรนี้ถูกเพิ่มเข้าบัญชีอื่นไปแล้ว');
            }

            if ($voucher->owner_user_id === null) {
                $voucher->update(['owner_user_id' => $user->id, 'claimed_at' => now()]);
            }

            return $voucher->fresh();
        });
    }

    // ── ใช้กับการจอง (BookingService) ────────────────────────────────

    /**
     * ล็อกบัตรสำหรับหักยอด — ต้องเรียกภายใน transaction ของการสร้างการจอง
     *
     * @throws \Exception ข้อความภาษาไทยที่บอกลูกค้าได้ตรง ๆ ว่าทำไมใช้ไม่ได้
     */
    public function lockForRedemption(string $code, int $userId): GiftVoucher
    {
        $voucher = GiftVoucher::where('code', GiftVoucher::normalizeCode($code))->lockForUpdate()->first();

        if (! $voucher || in_array($voucher->status, [GiftVoucher::STATUS_PENDING, GiftVoucher::STATUS_REJECTED], true)) {
            throw new \Exception('ไม่พบบัตรของขวัญนี้ กรุณาตรวจสอบรหัสอีกครั้ง');
        }

        throw_if($voucher->status === GiftVoucher::STATUS_UNDER_REVIEW, new \Exception('บัตรของขวัญนี้กำลังรอยืนยันการชำระเงิน ยังใช้ไม่ได้ครับ'));
        throw_if($voucher->status === GiftVoucher::STATUS_CANCELLED, new \Exception('บัตรของขวัญนี้ถูกยกเลิกแล้ว'));
        throw_unless($voucher->canBeUsedBy($userId), new \Exception('บัตรของขวัญนี้เป็นของบัญชีอื่น'));
        throw_if($voucher->isExpired(), new \Exception('บัตรของขวัญนี้หมดอายุแล้ว'));
        throw_if((float) $voucher->balance <= 0, new \Exception('บัตรของขวัญนี้ใช้ครบยอดแล้ว'));

        return $voucher;
    }

    /** หักยอดจากบัตรที่ล็อกไว้แล้ว — การใช้ครั้งแรกผูกบัตรเข้าบัญชีผู้ใช้ไปด้วย */
    public function redeem(GiftVoucher $voucher, Booking $booking, float $amount, int $userId): void
    {
        $amount = round($amount, 2);

        $voucher->balance = round((float) $voucher->balance - $amount, 2);
        if ($voucher->owner_user_id === null) {
            $voucher->owner_user_id = $userId;
            $voucher->claimed_at = now();
        }
        $voucher->save();

        $this->record($voucher, GiftVoucherTransaction::TYPE_REDEEM, -$amount, $booking->id, 'ใช้กับการจอง '.$booking->booking_ref, $userId);
    }

    /**
     * คืนยอดที่ใบจองใช้ไปกลับเข้าบัตร — ไม่เกินยอดที่ยังไม่ได้คืน เรียกซ้ำได้
     *
     * ไม่ผ่าน Eloquent save ของใบจอง เพราะถูกเรียกจากใน BookingObserver::updated()
     * การ save ซ้อนจะล้าง wasChanged('status') ที่ observer ยังต้องใช้ต่อ
     *
     * @return float ยอดที่คืนจริง
     */
    public function restoreForBooking(Booking $booking, float $amount, string $note, ?int $actorId = null): float
    {
        if (! $booking->gift_voucher_id || (float) $booking->voucher_amount <= 0) {
            return 0.0;
        }

        $restored = DB::transaction(function () use ($booking, $amount, $note, $actorId) {
            $fresh = Booking::query()->whereKey($booking->id)->lockForUpdate()
                ->first(['id', 'booking_ref', 'gift_voucher_id', 'voucher_amount', 'voucher_restored_amount']);
            $voucher = GiftVoucher::lockForUpdate()->find($fresh?->gift_voucher_id);

            if (! $fresh || ! $voucher) {
                return 0.0;
            }

            $remaining = round((float) $fresh->voucher_amount - (float) $fresh->voucher_restored_amount, 2);
            $amount = round(min(max($amount, 0.0), $remaining), 2);

            if ($amount <= 0) {
                return 0.0;
            }

            // บัตรที่ทีมงานยกเลิกไปแล้วรับยอดคืนไม่ได้ — ยอดส่วนนั้นต้องคืนเป็นเงินแทน
            if ($voucher->status === GiftVoucher::STATUS_CANCELLED) {
                Log::warning('Voucher restore skipped: voucher cancelled', ['voucher_id' => $voucher->id, 'booking_ref' => $fresh->booking_ref]);

                return 0.0;
            }

            $voucher->balance = round((float) $voucher->balance + $amount, 2);

            // ยอดที่คืนมาต้องมีเวลาให้ใช้ต่อ — บัตรที่หมดอายุหรือใกล้หมดถูกยืดออกไป
            $minUntil = now()->addDays((int) config('gift_voucher.restore_min_days_left', 90));
            if ($voucher->expires_at === null || $voucher->expires_at->lt($minUntil)) {
                $voucher->expires_at = $minUntil;
            }
            $voucher->save();

            $this->record($voucher, GiftVoucherTransaction::TYPE_RESTORE, $amount, $fresh->id, $note, $actorId);

            $newRestored = round((float) $fresh->voucher_restored_amount + $amount, 2);
            Booking::query()->whereKey($fresh->id)->update(['voucher_restored_amount' => $newRestored]);
            $booking->forceFill(['voucher_restored_amount' => $newRestored])->syncOriginalAttribute('voucher_restored_amount');

            return $amount;
        });

        if ($restored > 0) {
            $voucher = GiftVoucher::find($booking->gift_voucher_id);
            $userId = $voucher?->owner_user_id ?? $voucher?->purchaser_user_id;
            if ($voucher && $userId) {
                SmartNotification::send(
                    $userId,
                    'gift_voucher_restored',
                    'คืนยอดเข้าบัตรของขวัญแล้ว',
                    'การจอง '.$booking->booking_ref.' ถูกยกเลิก ยอด ฿'.number_format($restored, 2)
                        .' กลับเข้าบัตรของขวัญแล้ว ใช้จองทริปถัดไปได้เลยครับ',
                    ['voucher_id' => $voucher->id, 'route' => 'gift_voucher'],
                );
            }
        }

        return $restored;
    }

    // ── ภายใน ──────────────────────────────────────────────────────

    private function record(GiftVoucher $voucher, string $type, float $amount, ?int $bookingId, ?string $note, ?int $actorId): void
    {
        GiftVoucherTransaction::create([
            'gift_voucher_id' => $voucher->id,
            'booking_id' => $bookingId,
            'type' => $type,
            'amount' => round($amount, 2),
            'balance_after' => round((float) $voucher->balance, 2),
            'note' => $note !== null ? mb_substr($note, 0, 300) : null,
            'actor_user_id' => $actorId,
        ]);
    }

    private function announceActivated(GiftVoucher $voucher): void
    {
        if ($voucher->purchaser_user_id) {
            $forSelf = $voucher->owner_user_id === $voucher->purchaser_user_id;
            SmartNotification::send(
                $voucher->purchaser_user_id,
                'gift_voucher_active',
                'บัตรของขวัญพร้อมใช้แล้ว 🎁',
                $forSelf
                    ? 'บัตรมูลค่า ฿'.number_format((float) $voucher->amount).' อยู่ในบัญชีของคุณแล้ว ใช้ตอนจองทริปได้เลยครับ'
                    : 'บัตรมูลค่า ฿'.number_format((float) $voucher->amount).' พร้อมส่งต่อแล้ว แตะเพื่อแชร์ให้คนพิเศษได้เลยครับ',
                ['voucher_id' => $voucher->id, 'route' => 'gift_voucher'],
            );
        }

        $this->mail->sendGiftVoucherIssuedEmail($voucher);
    }

    private function notifyAdminsToReview(GiftVoucher $voucher, string $reason): void
    {
        try {
            User::role(['admin', 'operator'])->each(function (User $admin) use ($voucher, $reason) {
                SmartNotification::send(
                    $admin->id,
                    'gift_voucher_slip_review',
                    'สลิปบัตรของขวัญต้องตรวจสอบ',
                    'บัตร '.$voucher->displayCode().' ฿'.number_format((float) $voucher->amount)." (สาเหตุ: {$reason}) กรุณาตรวจและอนุมัติ",
                    ['voucher_id' => $voucher->id, 'route' => 'admin.gift_vouchers'],
                );
            });
        } catch (\Throwable $e) {
            Log::warning('notifyAdminsToReview (voucher) failed — '.$e->getMessage());
        }
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
