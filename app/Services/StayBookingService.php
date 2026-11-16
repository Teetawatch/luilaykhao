<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\Stay;
use App\Models\StayBooking;
use App\Models\StayPayout;
use App\Models\StayReview;
use App\Models\StayRoomNight;
use App\Models\StayRoomType;
use App\Models\User;
use App\Support\MediaDisk;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ใบจองที่พักชุมชน — ทุกการเปลี่ยนสถานะผ่านที่นี่ ทุกเมธอดตรวจสถานะใต้ lock
 *
 * กติกาเรื่องเงิน:
 * - ลูกค้าจ่ายเข้าบริษัท (พร้อมเพย์ + สลิป) เหมือนทริป ยอดคิดที่หลังบ้านเสมอ
 * - บริษัทเก็บ commission_percent ของยอด ที่เหลือ (host_amount) โอนให้เจ้าของ
 *   หลังแขกเข้าพักจริง (completed) ไม่ใช่ตอนจอง — แขกยกเลิกก่อนเข้าพักจะได้ไม่ต้องตามเงินคืน
 * - ยกเลิกหลังจ่าย: คืนตามนโยบายของที่พัก ส่วนที่ไม่คืน (ยกเลิกกระชั้น) แบ่งตามสัดส่วนเดิม
 *   เจ้าของยังได้ส่วนของเขา เพราะห้องคืนนั้นขายไม่ได้แล้ว
 * - ที่พักยกเลิกเอง/ทีมงานยกเลิก: คืนเต็มจำนวน
 *
 * ห้องว่าง: โหมดจองทันทีกันห้องตอนสร้างใบ (รอจ่าย instant_payment_minutes นาที)
 * โหมดส่งคำขอยังไม่กันห้องจนกว่าเจ้าของ/ทีมงานยืนยัน — ตรวจซ้ำตอนยืนยันใต้ lock
 */
class StayBookingService
{
    /** ใบที่ยังเปิดอยู่พร้อมกันได้ไม่เกินนี้ต่อบัญชี — กันกดจองกักห้อง */
    public const MAX_OPEN_PER_USER = 5;

    public function __construct(
        private StayAvailabilityService $availability,
        private SlipOcrService $slipOcr,
        private PromptPayService $promptPay,
    ) {}

    // ── ลูกค้า ──────────────────────────────────────────────────────

    /**
     * @param  array{room_type_id: int, check_in: string, check_out: string, units?: int, guests: int, guest_name: string, guest_phone: string, guest_email?: ?string, note?: ?string, experiences?: array, trip_booking_ref?: ?string}  $data
     *
     * @throws \Exception
     */
    public function create(User $user, Stay $stay, array $data): StayBooking
    {
        if (! $stay->isBookable()) {
            throw new \Exception('ที่พักนี้ปิดรับจองชั่วคราว');
        }

        $this->assertDates($data['check_in'], $data['check_out']);

        $open = StayBooking::where('user_id', $user->id)
            ->whereIn('status', [StayBooking::STATUS_REQUESTED, StayBooking::STATUS_AWAITING_PAYMENT, StayBooking::STATUS_PAYMENT_REVIEW])
            ->count();
        if ($open >= self::MAX_OPEN_PER_USER) {
            throw new \Exception('มีใบจองที่พักที่ยังไม่เสร็จ '.$open.' ใบ จ่ายหรือยกเลิกใบเดิมก่อนนะครับ');
        }

        $tripBookingId = null;
        if (filled($data['trip_booking_ref'] ?? null)) {
            $tripBookingId = Booking::where('booking_ref', trim((string) $data['trip_booking_ref']))
                ->where('user_id', $user->id)
                ->value('id');
        }

        $units = max(1, (int) ($data['units'] ?? 1));
        $guests = (int) $data['guests'];
        $instant = $stay->booking_mode === Stay::MODE_INSTANT;

        $booking = DB::transaction(function () use ($user, $stay, $data, $units, $guests, $instant, $tripBookingId) {
            // ล็อกแถวห้องไว้ — สองคนกดจองห้องสุดท้ายพร้อมกัน คนที่สองต้องเห็นว่าเต็ม
            $room = StayRoomType::where('stay_id', $stay->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->find((int) $data['room_type_id']);

            if (! $room) {
                throw new \Exception('ไม่พบห้องที่เลือก');
            }

            $free = $this->availability->availableUnits($room, $data['check_in'], $data['check_out']);
            if ($free < $units) {
                throw new \Exception($free === 0
                    ? 'ห้องนี้เต็มในวันที่เลือกแล้ว ลองเปลี่ยนวันหรือเลือกห้องอื่นนะครับ'
                    : "ห้องนี้เหลือว่างแค่ {$free} ห้องในวันที่เลือก");
            }

            $quote = $this->availability->quote($stay, $room, $data['check_in'], $data['check_out'], $units, $guests, $data['experiences'] ?? []);

            return StayBooking::create([
                'ref' => StayBooking::generateRef(),
                'user_id' => $user->id,
                'stay_id' => $stay->id,
                'room_type_id' => $room->id,
                'trip_booking_id' => $tripBookingId,
                'room_name' => $room->name,
                'check_in' => $data['check_in'],
                'check_out' => $data['check_out'],
                'nights' => $quote['nights'],
                'units' => $units,
                'guests' => $guests,
                'guest_name' => trim((string) $data['guest_name']),
                'guest_phone' => trim((string) $data['guest_phone']),
                'guest_email' => self::clean($data['guest_email'] ?? null),
                'note' => self::clean($data['note'] ?? null),
                'status' => $instant ? StayBooking::STATUS_AWAITING_PAYMENT : StayBooking::STATUS_REQUESTED,
                'booking_mode' => $instant ? Stay::MODE_INSTANT : Stay::MODE_REQUEST,
                'nightly_prices' => $quote['nightly_prices'],
                'room_amount' => $quote['room_amount'],
                'extra_guest_amount' => $quote['extra_guest_amount'],
                'experiences' => $quote['experiences'],
                'experiences_amount' => $quote['experiences_amount'],
                'total_amount' => $quote['total_amount'],
                'commission_percent' => $quote['commission_percent'],
                'commission_amount' => $quote['commission_amount'],
                'host_amount' => $quote['host_amount'],
                'cancellation_policy' => $stay->cancellation_policy,
                'payment_due_at' => $instant ? now()->addMinutes((int) config('stays.instant_payment_minutes', 30)) : null,
            ]);
        });

        $booking->setRelation('stay', $stay);

        if ($instant) {
            $this->notifyCustomer($booking, 'stay_booking_created', 'จองที่พักแล้ว รอชำระเงิน',
                "{$stay->name} · ".$this->dateRange($booking).' — ชำระภายใน '.config('stays.instant_payment_minutes', 30).' นาทีเพื่อยืนยันห้อง');
        } else {
            $this->notifyTeam($booking, 'stay_request_new', 'คำขอจองที่พักใหม่',
                "{$booking->guest_name} · {$stay->name} · ".$this->dateRange($booking).' · '.$booking->guests.' คน');
            $this->notifyHost($stay, 'stay_host_new_request', 'มีคำขอจองที่พักใหม่ 🛎️',
                $booking->room_name.' · '.$this->dateRange($booking).' · '.$booking->guests.' คน — กดยืนยันว่าห้องว่างได้ที่ลิงก์');
        }

        return $booking;
    }

    /** QR พร้อมเพย์และบัญชีโอน — ยอดมาจากหลังบ้านเสมอ */
    public function paymentInfo(StayBooking $booking): array
    {
        $amount = round((float) $booking->total_amount, 2);
        $payload = $this->promptPay->buildPayload((string) config('payment.promptpay_id'), $amount);

        return [
            'ref' => $booking->ref,
            'amount' => $amount,
            'payment_due_at' => $booking->payment_due_at?->toISOString(),
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
     * รับสลิป — ยืนยันทันทีเมื่อ OCR อ่านยอดตรง นอกนั้นส่งให้ทีมงานตรวจ
     *
     * @throws \Exception
     */
    public function submitSlip(StayBooking $booking, UploadedFile $slip): StayBooking
    {
        if ($booking->status !== StayBooking::STATUS_AWAITING_PAYMENT) {
            throw new \Exception(match ($booking->status) {
                StayBooking::STATUS_PAYMENT_REVIEW => 'ได้รับสลิปแล้ว ทีมงานกำลังตรวจสอบอยู่ครับ',
                StayBooking::STATUS_CONFIRMED, StayBooking::STATUS_COMPLETED => 'ใบจองนี้ชำระเงินเรียบร้อยแล้ว',
                StayBooking::STATUS_REQUESTED => 'รอที่พักยืนยันห้องว่างก่อน แล้วค่อยชำระเงินนะครับ',
                default => 'ใบจองนี้ปิดไปแล้ว',
            });
        }
        if ($booking->payment_due_at && $booking->payment_due_at->isPast()) {
            throw new \Exception('เลยเวลาชำระเงินแล้ว ห้องถูกปล่อยให้คนอื่น กรุณาจองใหม่');
        }

        $path = $slip->store('slips/stays/'.date('Y/m'), MediaDisk::slipDisk());

        $claimed = DB::transaction(function () use ($booking, $path) {
            $locked = StayBooking::lockForUpdate()->find($booking->id);
            if (! $locked || $locked->status !== StayBooking::STATUS_AWAITING_PAYMENT) {
                return null;
            }

            $locked->update([
                'slip_path' => $path,
                'slip_ocr_status' => SlipOcrService::STATUS_PENDING,
                'slip_ocr_result' => null,
                'status' => StayBooking::STATUS_PAYMENT_REVIEW,
                'payment_ref' => 'STPAY-'.strtoupper(uniqid()),
                'payment_note' => null,
            ]);

            return $locked;
        });

        if (! $claimed) {
            throw new \Exception('ได้รับสลิปแล้ว ทีมงานกำลังตรวจสอบอยู่ครับ');
        }

        $result = $this->slipOcr->verify($path, (float) $claimed->total_amount);
        $claimed->update([
            'slip_ocr_status' => $result['status'],
            'slip_ocr_result' => $result['raw'] ?? null,
        ]);

        if ($result['status'] === SlipOcrService::STATUS_VERIFIED) {
            return $this->confirmPayment($claimed);
        }

        $claimed->loadMissing('stay');
        $this->notifyTeam($claimed, 'stay_slip_review', 'สลิปค่าที่พักต้องตรวจสอบ',
            "{$claimed->ref} · {$claimed->stay->name} ฿".number_format((float) $claimed->total_amount).' (สาเหตุ: '.($result['reason'] ?? 'unknown').')');
        $this->notifyCustomer($claimed, 'stay_payment_review', 'ได้รับสลิปค่าที่พักแล้ว',
            'ทีมงานกำลังตรวจยอดโอน จะยืนยันห้องให้เร็วที่สุดครับ');

        return $claimed->fresh();
    }

    /**
     * พรีวิวยอดคืนก่อนกดยกเลิก
     *
     * @return array{refund_amount: float, refund_percent: int, paid_amount: float, days_before: int, policy: string, policy_label: string, needs_account: bool}
     */
    public function cancellationPreview(StayBooking $booking): array
    {
        $paid = $booking->isPaid() ? (float) $booking->paid_amount : 0.0;
        $days = $this->daysBeforeCheckIn($booking);
        $percent = $paid > 0 ? $this->refundPercent($booking->cancellation_policy, $days) : 0;
        $refund = round($paid * $percent / 100, 2);

        return [
            'refund_amount' => $refund,
            'refund_percent' => $percent,
            'paid_amount' => $paid,
            'days_before' => $days,
            'policy' => $booking->cancellation_policy,
            'policy_label' => (string) config('stays.policies.'.$booking->cancellation_policy.'.label', ''),
            'needs_account' => $refund > 0,
        ];
    }

    /**
     * ลูกค้ายกเลิกเอง — คืนตามนโยบาย
     *
     * @param  array{bank?: ?string, number?: ?string, name?: ?string}|null  $refundAccount
     *
     * @throws \Exception
     */
    public function cancelByCustomer(StayBooking $booking, ?string $reason, ?array $refundAccount): StayBooking
    {
        if (! $booking->canCancel()) {
            throw new \Exception('ใบจองนี้ยกเลิกเองไม่ได้แล้ว กรุณาติดต่อทีมงานผ่านแชทในแอปครับ');
        }

        $preview = $this->cancellationPreview($booking);
        if ($preview['refund_amount'] > 0 && ! self::accountComplete($refundAccount)) {
            throw new \Exception('กรุณากรอกบัญชีรับเงินคืน (ธนาคาร/พร้อมเพย์ เลขบัญชี และชื่อบัญชี)');
        }

        return $this->cancel($booking, 'customer', $reason, $preview['refund_amount'], $refundAccount);
    }

    /**
     * ส่งรีวิวหลังเข้าพัก
     *
     * @throws \Exception
     */
    public function review(StayBooking $booking, int $rating, ?string $comment): StayReview
    {
        if (! $booking->canReview()) {
            throw new \Exception('รีวิวได้หลังเข้าพักแล้วเท่านั้น');
        }
        if ($booking->review()->exists()) {
            throw new \Exception('รีวิวใบจองนี้ไปแล้ว ขอบคุณครับ');
        }

        $review = StayReview::create([
            'stay_id' => $booking->stay_id,
            'stay_booking_id' => $booking->id,
            'user_id' => $booking->user_id,
            'rating' => max(1, min(5, $rating)),
            'comment' => self::clean($comment),
        ]);

        $this->refreshRating($booking->stay_id);

        return $review;
    }

    public function refreshRating(int $stayId): void
    {
        $stats = StayReview::where('stay_id', $stayId)->where('is_hidden', false)
            ->selectRaw('COUNT(*) as c, AVG(rating) as a')->first();

        Stay::whereKey($stayId)->update([
            'rating_count' => (int) ($stats->c ?? 0),
            'rating_avg' => ($stats->c ?? 0) > 0 ? round((float) $stats->a, 2) : null,
        ]);
    }

    // ── เจ้าของที่พัก / ทีมงาน ───────────────────────────────────────

    /**
     * ยืนยันว่าห้องว่าง (โหมดส่งคำขอ) — ลูกค้าได้เวลาจ่ายเงิน
     *
     * @param  string  $by  host | staff
     * @param  float|null  $adjustedTotal  ทีมงานปรับยอดได้ (ต่อรองแล้ว) เจ้าของปรับไม่ได้
     *
     * @throws \Exception
     */
    public function approveRequest(StayBooking $booking, string $by, ?float $adjustedTotal = null): StayBooking
    {
        $approved = DB::transaction(function () use ($booking, $by, $adjustedTotal) {
            $locked = StayBooking::lockForUpdate()->findOrFail($booking->id);
            if ($locked->status !== StayBooking::STATUS_REQUESTED) {
                throw new \Exception('คำขอนี้ตอบไปแล้ว');
            }

            $room = StayRoomType::lockForUpdate()->find($locked->room_type_id);
            if (! $room) {
                throw new \Exception('ห้องนี้ถูกลบไปแล้ว');
            }

            $free = $this->availability->availableUnits($room, $locked->check_in->toDateString(), $locked->check_out->toDateString(), $locked->id);
            if ($free < $locked->units) {
                throw new \Exception('ห้องในวันนั้นเต็มแล้ว (มีใบอื่นจองไปก่อน) กรุณาปฏิเสธคำขอนี้แทน');
            }

            $updates = [
                'status' => StayBooking::STATUS_AWAITING_PAYMENT,
                'responded_at' => now(),
                'responded_by' => $by,
                'payment_due_at' => now()->addHours((int) config('stays.request_payment_hours', 24)),
            ];

            if ($adjustedTotal !== null && $adjustedTotal > 0 && abs($adjustedTotal - (float) $locked->total_amount) >= 0.01) {
                $updates['total_amount'] = round($adjustedTotal, 2);
                $updates += StayAvailabilityService::split($adjustedTotal, (float) $locked->commission_percent);
            }

            $locked->update($updates);

            return $locked;
        });

        $approved->load('stay');
        $this->notifyCustomer($approved, 'stay_request_approved', 'ที่พักยืนยันห้องว่างแล้ว ✅',
            "{$approved->stay->name} · ".$this->dateRange($approved).' · ยอด ฿'.number_format((float) $approved->total_amount)
                .' — ชำระภายใน '.config('stays.request_payment_hours', 24).' ชั่วโมงเพื่อยืนยันห้อง');

        return $approved->fresh();
    }

    /** @throws \Exception */
    public function declineRequest(StayBooking $booking, string $by, ?string $reason): StayBooking
    {
        $declined = DB::transaction(function () use ($booking, $by, $reason) {
            $locked = StayBooking::lockForUpdate()->findOrFail($booking->id);
            if ($locked->status !== StayBooking::STATUS_REQUESTED) {
                throw new \Exception('คำขอนี้ตอบไปแล้ว');
            }

            $locked->update([
                'status' => StayBooking::STATUS_DECLINED,
                'responded_at' => now(),
                'responded_by' => $by,
                'decline_reason' => self::clean($reason),
            ]);

            return $locked;
        });

        $declined->load('stay');
        $this->notifyCustomer($declined, 'stay_request_declined', 'ห้องไม่ว่างในวันที่ขอ',
            "{$declined->stay->name} · ".$this->dateRange($declined)
                .($declined->decline_reason ? " — {$declined->decline_reason}" : '').' ลองเลือกวันอื่นหรือที่พักใกล้เคียงได้เลยครับ');

        return $declined->fresh();
    }

    // ── ทีมงาน ──────────────────────────────────────────────────────

    /**
     * ยืนยันการจ่ายเงิน (OCR ผ่าน หรือทีมงานอนุมัติสลิป) — เรียกซ้ำได้
     */
    public function confirmPayment(StayBooking $booking, ?User $reviewer = null): StayBooking
    {
        $confirmed = DB::transaction(function () use ($booking, $reviewer) {
            $locked = StayBooking::lockForUpdate()->findOrFail($booking->id);

            if (in_array($locked->status, [StayBooking::STATUS_CONFIRMED, StayBooking::STATUS_COMPLETED], true)) {
                return null;
            }
            if (! in_array($locked->status, [StayBooking::STATUS_PAYMENT_REVIEW, StayBooking::STATUS_AWAITING_PAYMENT], true)) {
                throw new \Exception('ใบจองนี้ไม่ได้รอการชำระเงิน');
            }

            $locked->update([
                'status' => StayBooking::STATUS_CONFIRMED,
                'paid_at' => now(),
                'paid_amount' => $locked->total_amount,
                'payment_reviewed_by_id' => $reviewer?->id,
                'slip_ocr_status' => $reviewer ? SlipOcrService::STATUS_APPROVED : $locked->slip_ocr_status,
            ]);

            return $locked;
        });

        if (! $confirmed) {
            return $booking->fresh();
        }

        $confirmed->load('stay');
        $stay = $confirmed->stay;

        $this->notifyCustomer($confirmed, 'stay_booking_confirmed', 'ยืนยันที่พักแล้ว 🏡',
            "{$stay->name} · ".$this->dateRange($confirmed).' · เช็คอินตั้งแต่ '.$stay->check_in_time.' น.');
        $this->notifyHost($stay, 'stay_host_new_booking', 'มีแขกจองที่พักแล้ว 🎉',
            "{$confirmed->guest_name} · {$confirmed->room_name} · ".$this->dateRange($confirmed).' · '.$confirmed->guests.' คน');

        return $confirmed->fresh();
    }

    /** สลิปไม่ผ่าน — กลับไปรอจ่าย ได้เวลาเพิ่มเพื่อส่งสลิปใหม่ */
    public function rejectSlip(StayBooking $booking, User $reviewer, string $note): StayBooking
    {
        $rejected = DB::transaction(function () use ($booking, $reviewer, $note) {
            $locked = StayBooking::lockForUpdate()->findOrFail($booking->id);
            if ($locked->status !== StayBooking::STATUS_PAYMENT_REVIEW) {
                throw new \Exception('ใบจองนี้ไม่ได้รอตรวจสลิป');
            }

            $locked->update([
                'status' => StayBooking::STATUS_AWAITING_PAYMENT,
                'slip_ocr_status' => SlipOcrService::STATUS_REJECTED,
                'payment_reviewed_by_id' => $reviewer->id,
                'payment_note' => mb_substr($note, 0, 300),
                'payment_due_at' => now()->addHours((int) config('stays.request_payment_hours', 24)),
            ]);

            return $locked;
        });

        $this->notifyCustomer($rejected, 'stay_payment_rejected', 'สลิปค่าที่พักไม่ผ่านการตรวจ',
            "{$rejected->ref}: {$note} — กรุณาส่งสลิปใหม่ภายใน ".config('stays.request_payment_hours', 24).' ชั่วโมง');

        return $rejected->fresh();
    }

    /**
     * ทีมงานยกเลิก (ที่พักมีเหตุ/ลูกค้าขอผ่านแชท) — คืนเต็มจำนวนถ้าไม่ระบุ
     *
     * @throws \Exception
     */
    public function cancelByStaff(StayBooking $booking, string $reason, ?float $refundAmount = null): StayBooking
    {
        if (in_array($booking->status, [StayBooking::STATUS_CANCELLED, StayBooking::STATUS_DECLINED, StayBooking::STATUS_EXPIRED], true)) {
            throw new \Exception('ใบจองนี้ปิดไปแล้ว');
        }

        $paid = $booking->isPaid() ? (float) $booking->paid_amount : 0.0;
        $refund = $refundAmount === null ? $paid : max(0.0, min($paid, round($refundAmount, 2)));

        return $this->cancel($booking, 'staff', $reason, $refund, null);
    }

    /**
     * บันทึกว่าโอนเงินคืนแล้ว
     *
     * @throws \Exception
     */
    public function markRefunded(StayBooking $booking, ?string $note): StayBooking
    {
        $refunded = DB::transaction(function () use ($booking, $note) {
            $locked = StayBooking::lockForUpdate()->findOrFail($booking->id);
            if ($locked->refund_status !== StayBooking::REFUND_PENDING) {
                throw new \Exception('ใบจองนี้ไม่มีเงินคืนค้างอยู่');
            }

            $locked->update([
                'refund_status' => StayBooking::REFUND_REFUNDED,
                'refunded_at' => now(),
                'refund_note' => self::clean($note),
            ]);

            return $locked;
        });

        $this->notifyCustomer($refunded, 'stay_refunded', 'คืนเงินค่าที่พักแล้ว',
            "{$refunded->ref} · โอนคืน ฿".number_format((float) $refunded->refund_amount).' เข้าบัญชีที่แจ้งไว้เรียบร้อยครับ');

        return $refunded->fresh();
    }

    /** ลูกค้าแจ้ง/แก้บัญชีรับเงินคืน ระหว่างรอคืน */
    public function updateRefundAccount(StayBooking $booking, array $account): StayBooking
    {
        if ($booking->refund_status !== StayBooking::REFUND_PENDING) {
            throw new \Exception('ใบจองนี้ไม่มีเงินคืนค้างอยู่');
        }
        if (! self::accountComplete($account)) {
            throw new \Exception('กรุณากรอกบัญชีรับเงินคืนให้ครบ');
        }

        $booking->update(['refund_account' => self::normaliseAccount($account)]);
        $this->notifyTeam($booking, 'stay_refund_account', 'ลูกค้าแจ้งบัญชีรับเงินคืนค่าที่พัก', "{$booking->ref} พร้อมโอนคืนแล้ว");

        return $booking->fresh();
    }

    /**
     * โอนเงินให้เจ้าของที่พัก — กวาดทุกใบที่ถึงรอบจ่ายแล้วยังไม่ได้จ่าย
     *
     * @throws \Exception
     */
    public function payout(Stay $stay, User $admin, ?string $reference, ?string $note): StayPayout
    {
        $payout = DB::transaction(function () use ($stay, $admin, $reference, $note) {
            $rows = $this->payableQuery($stay)->lockForUpdate()->get();
            $amount = round((float) $rows->sum('host_amount'), 2);

            if ($rows->isEmpty() || $amount <= 0) {
                throw new \Exception('ยังไม่มียอดที่ถึงรอบจ่ายให้ที่พักนี้');
            }

            $payout = StayPayout::create([
                'stay_id' => $stay->id,
                'amount' => $amount,
                'booking_count' => $rows->count(),
                'paid_at' => now(),
                'reference' => self::clean($reference),
                'note' => self::clean($note),
                'paid_by_id' => $admin->id,
            ]);

            StayBooking::whereIn('id', $rows->pluck('id'))->update(['payout_id' => $payout->id]);

            return $payout;
        });

        $this->notifyHost($stay, 'stay_payout', 'โอนรายได้ค่าที่พักแล้ว 💸',
            'ยอด ฿'.number_format((float) $payout->amount).' จาก '.$payout->booking_count.' ใบจอง ขอบคุณที่ดูแลแขกของเราครับ');

        return $payout;
    }

    /** ใบที่ถึงรอบจ่ายเจ้าของแล้ว (เข้าพักจบ หรือยกเลิกแต่มีส่วนที่ไม่คืนและคืนเงินลูกค้าเสร็จแล้ว) */
    public function payableQuery(Stay $stay)
    {
        return StayBooking::where('stay_id', $stay->id)
            ->whereNotNull('payout_eligible_at')
            ->whereNull('payout_id')
            ->where('host_amount', '>', 0)
            ->where(fn ($q) => $q->whereNull('refund_status')->orWhere('refund_status', StayBooking::REFUND_REFUNDED));
    }

    /**
     * ปิด/เปิดห้อง หรือตั้งราคาพิเศษเป็นช่วง — จากหน้าของเจ้าของหรือหลังบ้าน
     *
     * @param  int|null  $closedUnits  null = ไม่เปลี่ยน
     * @param  float|null|false  $price  false = ไม่เปลี่ยน, null = ลบราคาพิเศษ
     *
     * @throws \Exception
     */
    public function setNights(StayRoomType $room, string $from, string $to, ?int $closedUnits, float|null|false $price): int
    {
        if ($to < $from) {
            throw new \Exception('วันสิ้นสุดต้องไม่ก่อนวันเริ่ม');
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366) {
            throw new \Exception('ตั้งได้ครั้งละไม่เกิน 1 ปี');
        }

        $count = 0;
        DB::transaction(function () use ($room, $from, $to, $closedUnits, $price, &$count) {
            foreach (CarbonPeriod::create($from, $to) as $day) {
                $night = StayRoomNight::firstOrNew(['room_type_id' => $room->id, 'night' => $day->toDateString()]);
                if ($closedUnits !== null) {
                    $night->closed_units = max(0, min((int) $room->units, $closedUnits));
                }
                if ($price !== false) {
                    $night->price_override = $price;
                }

                // แถวที่ไม่ได้ยกเว้นอะไรแล้ว ไม่ต้องเก็บ
                if ((int) $night->closed_units === 0 && $night->price_override === null) {
                    if ($night->exists) {
                        $night->delete();
                    }
                } else {
                    $night->save();
                }
                $count++;
            }
        });

        return $count;
    }

    // ── งานตามเวลา ───────────────────────────────────────────────────

    /** ใบรอจ่ายที่เลยเวลา → หมดเวลา (ห้องกลับมาขาย) */
    public function expireUnpaid(): int
    {
        $count = 0;
        StayBooking::where('status', StayBooking::STATUS_AWAITING_PAYMENT)
            ->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<', now())
            ->with('stay')
            ->each(function (StayBooking $booking) use (&$count) {
                $changed = StayBooking::whereKey($booking->id)
                    ->where('status', StayBooking::STATUS_AWAITING_PAYMENT)
                    ->update(['status' => StayBooking::STATUS_EXPIRED]);
                if ($changed) {
                    $count++;
                    $this->notifyCustomer($booking, 'stay_booking_expired', 'ใบจองที่พักหมดเวลาชำระ',
                        "{$booking->stay->name} · ".$this->dateRange($booking).' — ห้องถูกปล่อยแล้ว จองใหม่ได้ถ้ายังว่างอยู่ครับ');
                }
            });

        return $count;
    }

    /** คำขอที่ไม่มีใครตอบนานเกิน → หมดเวลา */
    public function expireStaleRequests(): int
    {
        $count = 0;
        StayBooking::where('status', StayBooking::STATUS_REQUESTED)
            ->where(fn ($q) => $q->where('created_at', '<', now()->subHours((int) config('stays.request_expire_hours', 48)))
                ->orWhere('check_in', '<=', now('Asia/Bangkok')->toDateString()))
            ->with('stay')
            ->each(function (StayBooking $booking) use (&$count) {
                $changed = StayBooking::whereKey($booking->id)
                    ->where('status', StayBooking::STATUS_REQUESTED)
                    ->update(['status' => StayBooking::STATUS_EXPIRED]);
                if ($changed) {
                    $count++;
                    $this->notifyCustomer($booking, 'stay_request_expired', 'คำขอจองที่พักยังไม่ได้รับการยืนยัน',
                        "{$booking->stay->name} · ".$this->dateRange($booking).' — ขออภัยที่ยืนยันไม่ทัน ลองที่พักอื่นใกล้ทริปหรือทักทีมงานได้เลยครับ');
                }
            });

        return $count;
    }

    /** เข้าพักครบแล้ว → completed, ถึงรอบจ่ายเจ้าของ, ชวนรีวิว */
    public function completeFinished(): int
    {
        $today = now('Asia/Bangkok')->toDateString();
        $count = 0;

        StayBooking::where('status', StayBooking::STATUS_CONFIRMED)
            ->where('check_out', '<=', $today)
            ->with('stay')
            ->each(function (StayBooking $booking) use (&$count) {
                $changed = StayBooking::whereKey($booking->id)
                    ->where('status', StayBooking::STATUS_CONFIRMED)
                    ->update([
                        'status' => StayBooking::STATUS_COMPLETED,
                        'completed_at' => now(),
                        'payout_eligible_at' => now(),
                        'review_invited_at' => now(),
                    ]);
                if ($changed) {
                    $count++;
                    $this->notifyCustomer($booking, 'stay_review_invite', 'พักที่ '.$booking->stay->name.' เป็นยังไงบ้าง?',
                        'ให้คะแนนสั้น ๆ ช่วยให้ชุมชนได้แขกคนต่อไป และช่วยนักเดินทางคนอื่นตัดสินใจครับ');
                }
            });

        return $count;
    }

    // ── ภายใน ──────────────────────────────────────────────────────

    private function cancel(StayBooking $booking, string $by, ?string $reason, float $refund, ?array $refundAccount): StayBooking
    {
        $cancelled = DB::transaction(function () use ($booking, $by, $reason, $refund, $refundAccount) {
            $locked = StayBooking::lockForUpdate()->findOrFail($booking->id);
            if (in_array($locked->status, [StayBooking::STATUS_CANCELLED, StayBooking::STATUS_DECLINED, StayBooking::STATUS_EXPIRED, StayBooking::STATUS_COMPLETED], true)) {
                throw new \Exception('ใบจองนี้ปิดไปแล้ว');
            }

            $paid = $locked->isPaid() ? (float) $locked->paid_amount : 0.0;
            $refund = min($refund, $paid);
            $retained = round($paid - $refund, 2);

            $updates = [
                'status' => StayBooking::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $by,
                'cancel_reason' => self::clean($reason),
                'refund_amount' => $refund,
                'refund_status' => $refund > 0 ? StayBooking::REFUND_PENDING : null,
                'refund_account' => $refund > 0 && $refundAccount ? self::normaliseAccount($refundAccount) : null,
            ];

            // ส่วนที่ไม่คืน (ยกเลิกกระชั้น) ยังแบ่งให้เจ้าของตามสัดส่วนเดิม
            $updates += StayAvailabilityService::split($retained, (float) $locked->commission_percent);
            $updates['payout_eligible_at'] = $retained > 0 ? now() : null;

            $locked->update($updates);

            return $locked;
        });

        $cancelled->load('stay');
        $refundText = (float) $cancelled->refund_amount > 0
            ? ' · คืนเงิน ฿'.number_format((float) $cancelled->refund_amount).' ภายใน 7 วันทำการ'
            : '';

        $this->notifyCustomer($cancelled, 'stay_booking_cancelled', 'ยกเลิกการจองที่พักแล้ว',
            "{$cancelled->stay->name} · ".$this->dateRange($cancelled).$refundText);

        // เจ้าของรู้เฉพาะใบที่เขารู้อยู่แล้ว (ยืนยันห้อง/มีแขกจ่ายแล้ว) ใบที่ยังเป็นคำขอไม่ต้องรบกวน
        if ($cancelled->responded_at !== null || $cancelled->booking_mode === Stay::MODE_INSTANT) {
            $this->notifyHost($cancelled->stay, 'stay_host_cancelled', 'แขกยกเลิกการจอง',
                "{$cancelled->guest_name} · {$cancelled->room_name} · ".$this->dateRange($cancelled).' — ห้องกลับมาว่างแล้ว');
        }

        if ($cancelled->refund_status === StayBooking::REFUND_PENDING) {
            $this->notifyTeam($cancelled, 'stay_refund_pending', 'มีเงินคืนค่าที่พักรอโอน',
                "{$cancelled->ref} · ฿".number_format((float) $cancelled->refund_amount).($cancelled->refund_account ? '' : ' (รอลูกค้าแจ้งบัญชี)'));
        }

        return $cancelled->fresh();
    }

    private function assertDates(string $checkIn, string $checkOut): void
    {
        $today = now('Asia/Bangkok')->toDateString();
        if ($checkIn < $today) {
            throw new \Exception('วันเข้าพักต้องไม่ใช่วันที่ผ่านมาแล้ว');
        }
        if ($checkOut <= $checkIn) {
            throw new \Exception('วันออกต้องอยู่หลังวันเข้าพัก');
        }
        $nights = CarbonImmutable::parse($checkIn)->diffInDays(CarbonImmutable::parse($checkOut));
        if ($nights > (int) config('stays.max_nights', 14)) {
            throw new \Exception('จองได้ครั้งละไม่เกิน '.config('stays.max_nights', 14).' คืน');
        }
        if (CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($checkIn)) > (int) config('stays.max_advance_days', 365)) {
            throw new \Exception('จองล่วงหน้าได้ไม่เกิน 1 ปี');
        }
    }

    private function daysBeforeCheckIn(StayBooking $booking): int
    {
        $today = CarbonImmutable::parse(now('Asia/Bangkok')->toDateString());

        return (int) $today->diffInDays(CarbonImmutable::parse($booking->check_in->toDateString()), false);
    }

    private function refundPercent(string $policy, int $daysBefore): int
    {
        $tiers = collect(config('stays.policies.'.$policy.'.tiers', []))->sortByDesc('days');

        foreach ($tiers as $tier) {
            if ($daysBefore >= (int) $tier['days']) {
                return (int) $tier['percent'];
            }
        }

        return 0;
    }

    private function dateRange(StayBooking $booking): string
    {
        return ThaiDate::short($booking->check_in).' – '.ThaiDate::short($booking->check_out).' ('.$booking->nights.' คืน)';
    }

    private function notifyCustomer(StayBooking $booking, string $type, string $title, string $body): void
    {
        if (! $booking->user_id) {
            return;
        }

        try {
            SmartNotification::send($booking->user_id, $type, $title, $body, [
                'stay_booking_ref' => $booking->ref,
                'route' => 'stay_booking',
                'web_url' => $booking->webUrl(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('StayBookingService notifyCustomer failed — '.$e->getMessage());
        }
    }

    private function notifyHost(Stay $stay, string $type, string $title, string $body): void
    {
        if (! $stay->host_user_id) {
            return;
        }

        try {
            SmartNotification::send($stay->host_user_id, $type, $title, $body, array_filter([
                'stay_id' => $stay->id,
                'route' => 'stay_host',
                'web_url' => $stay->hostUrl(),
            ]));
        } catch (\Throwable $e) {
            Log::warning('StayBookingService notifyHost failed — '.$e->getMessage());
        }
    }

    private function notifyTeam(StayBooking $booking, string $type, string $title, string $body): void
    {
        try {
            User::role(['admin', 'operator'])->each(function (User $admin) use ($booking, $type, $title, $body) {
                SmartNotification::send($admin->id, $type, $title, $body, [
                    'stay_booking_ref' => $booking->ref,
                    'route' => 'admin.stay_bookings',
                ]);
            });
        } catch (\Throwable $e) {
            Log::warning('StayBookingService notifyTeam failed — '.$e->getMessage());
        }
    }

    private static function accountComplete(?array $account): bool
    {
        return $account !== null
            && filled($account['bank'] ?? null)
            && filled($account['number'] ?? null)
            && filled($account['name'] ?? null);
    }

    /** @return array{bank: string, number: string, name: string} */
    private static function normaliseAccount(array $account): array
    {
        return [
            'bank' => mb_substr(trim((string) ($account['bank'] ?? '')), 0, 60),
            'number' => mb_substr(trim((string) ($account['number'] ?? '')), 0, 30),
            'name' => mb_substr(trim((string) ($account['name'] ?? '')), 0, 120),
        ];
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
