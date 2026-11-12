<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\MediaDisk;
use App\Support\ThaiDate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ติดตามการคืนเงินทีละขั้น — เงินเข้าบัญชีบริษัทโดยตรง (พร้อมเพย์ + สลิป) จึงไม่มี
 * gateway ไหนโอนคืนให้อัตโนมัติ ทีมงานโอนเองทีละคน ระบบนี้มีไว้ให้ลูกค้ากรอกบัญชีเอง
 * เห็นว่าเรื่องอยู่ขั้นไหน และได้ push ทุกครั้งที่ขยับ
 *
 *   awaiting_account → requested → processing → refunded
 *   (รอลูกค้าแจ้งบัญชี)  (รอโอน)      (กำลังโอน)    (โอนแล้ว + สลิป)
 *
 * ทีมงานตีกลับบัญชีที่ผิดได้ (rejectAccount) → กลับไป awaiting_account พร้อมข้อความบอกเหตุ
 * ขั้นสุดท้ายยังผ่าน BookingService::processRefund() ตัวเดิม (ปุ่มคืนเงิน + แนบสลิป)
 */
class RefundService
{
    public const AWAITING_ACCOUNT = 'awaiting_account';

    public const REQUESTED = 'requested';

    public const PROCESSING = 'processing';

    public const REFUNDED = 'refunded';

    /** ยังไม่ปิดเรื่อง — ทีมงานยังต้องทำอะไรสักอย่าง หรือรอลูกค้า */
    public const OPEN_STATES = [self::AWAITING_ACCOUNT, self::REQUESTED, self::PROCESSING];

    /** ลูกค้ายังแก้บัญชีเองได้ (พอทีมงานเริ่มโอนแล้วต้องติดต่อทีมงานแทน) */
    public const EDITABLE_STATES = [self::AWAITING_ACCOUNT, self::REQUESTED];

    /** ตัวเลือกช่องทางรับเงินคืน — ทุกหน้าจอ (เว็บ แอป LIFF ลิงก์) ใช้รายการเดียวกันจากที่นี่ */
    public const BANKS = [
        'พร้อมเพย์',
        'กสิกรไทย',
        'ไทยพาณิชย์',
        'กรุงเทพ',
        'กรุงไทย',
        'กรุงศรีอยุธยา',
        'ทหารไทยธนชาต (ttb)',
        'ออมสิน',
        'ธ.ก.ส.',
        'อาคารสงเคราะห์',
        'ยูโอบี',
        'ซีไอเอ็มบี ไทย',
        'เกียรตินาคินภัทร',
        'แลนด์ แอนด์ เฮ้าส์',
        'อื่น ๆ',
    ];

    /** ยังไม่กรอกบัญชี — เตือนเมื่อเปิดเรื่องมาแล้วกี่วัน (เวลาไทย นับวัน ไม่ใช่ชั่วโมง) */
    public const ACCOUNT_REMINDER_DAYS = [2, 5];

    /** สัญญากับลูกค้าว่าโอนภายในเท่านี้หลังได้บัญชี — ข้อความเดียวกันทุกช่องทาง */
    public const ETA_LABEL = 'ภายใน 3–7 วันทำการ';

    public const TIMEZONE = 'Asia/Bangkok';

    public function __construct(
        private BookingService $bookings,
        private MailService $mail,
        private SmsService $sms,
    ) {}

    /**
     * ยกเลิกทั้งรอบ (ทริปไม่ออก) แล้วคืนเงินเต็มจำนวนทุกใบที่จ่ายมาแล้ว
     * ใบที่ยังไม่ได้จ่ายถูกยกเลิกเฉย ๆ ลูกค้าได้ push/อีเมล/SMS แจ้งยกเลิกปกติ
     *
     * ต่างจาก ForceMajeureService (เลื่อนรอบให้เลือกรอบใหม่) ตรงที่จบเลย ไม่มีรอบใหม่
     *
     * @return array{cancelled: int, refunds: int, refund_total: float}
     */
    public function cancelSchedule(TripSchedule $schedule, string $reason): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \Exception('กรุณาระบุเหตุผลที่ยกเลิกทริป');
        }

        [$schedule, $bookings, $waitlist] = DB::transaction(function () use ($schedule) {
            $schedule = TripSchedule::with('trip')->lockForUpdate()->findOrFail($schedule->id);

            if ($schedule->status === 'cancelled') {
                throw new \Exception('รอบนี้ถูกยกเลิกไปแล้ว');
            }

            // ตั้งรอบเป็นยกเลิกก่อน — งานที่ตามมาจาก cancelBooking (แจ้งที่ว่าง, คิวรอ)
            // ข้ามรอบที่ยกเลิกแล้ว จึงไม่มีใครได้ push ว่า "มีที่ว่าง" ในรอบที่ไม่ออก
            $schedule->forceFill(['status' => 'cancelled'])->save();

            $bookings = $schedule->bookings()
                ->whereIn('status', Booking::MODIFIABLE_STATUSES)
                ->orderBy('id')
                ->get();

            $waitlist = WaitlistEntry::where('schedule_id', $schedule->id)
                ->whereIn('status', ['waiting', 'offered'])
                ->get();
            WaitlistEntry::whereKey($waitlist->modelKeys())->update(['status' => 'cancelled']);

            return [$schedule, $bookings, $waitlist];
        });

        $refunds = 0;
        $total = 0.0;

        foreach ($bookings as $booking) {
            try {
                $paid = round((float) $booking->paid_amount, 2);

                if ($paid <= 0) {
                    $this->bookings->cancelBooking($booking, $reason);

                    continue;
                }

                $cancelled = $this->bookings->cancelBooking($booking, $reason, notify: false);
                $this->open($cancelled, $paid, full: true, tripCancelled: true);
                $refunds++;
                $total += $paid;
            } catch (\Throwable $e) {
                Log::error('Cancel schedule: booking refund failed', [
                    'booking_ref' => $booking->booking_ref,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->notifyWaitlist($schedule, $waitlist, $reason);
        $this->notifyStaff($schedule, $reason, $bookings->count());

        return ['cancelled' => $bookings->count(), 'refunds' => $refunds, 'refund_total' => round($total, 2)];
    }

    /**
     * เปิดเรื่องคืนเงินให้ใบจองที่ยกเลิกแล้ว — ลูกค้ามีบัญชีอยู่แล้วไปขั้น "รอโอน" เลย
     * ไม่มีก็รอลูกค้ากรอกในแอป
     *
     * @param  bool  $full  เราเป็นฝ่ายยกเลิก คืนทุกบาท (ซ่อนนโยบาย 80/50/0 ในอีเมล)
     * @param  bool  $tripCancelled  ข้อความบอกว่า "ทริปถูกยกเลิก" แทน "คืนเงิน"
     */
    public function open(Booking $booking, float $amount, bool $full = false, bool $tripCancelled = false, bool $notify = true): Booking
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new \Exception('ยอดคืนเงินต้องมากกว่า 0');
        }

        if ($amount > round((float) $booking->paid_amount, 2)) {
            throw new \Exception('ยอดคืนเงินต้องไม่เกิน ฿'.number_format((float) $booking->paid_amount, 2));
        }

        if ($booking->status === 'refunded' || $booking->refund_status === self::REFUNDED) {
            throw new \Exception('การจองนี้คืนเงินไปแล้ว');
        }

        if (in_array($booking->refund_status, self::OPEN_STATES, true)) {
            throw new \Exception('การจองนี้มีเรื่องคืนเงินเปิดอยู่แล้ว');
        }

        if ($booking->status !== 'cancelled') {
            throw new \Exception('ยกเลิกการจองก่อนเปิดเรื่องคืนเงิน');
        }

        $hasAccount = self::hasAccount($booking);

        $booking->forceFill([
            'refund_status' => $hasAccount ? self::REQUESTED : self::AWAITING_ACCOUNT,
            'refund_amount' => $amount,
            'refund_full' => $full,
            'refund_opened_at' => now(),
            'refund_account_at' => $hasAccount ? now() : null,
            'refund_processing_at' => null,
            'refund_note' => null,
        ])->save();

        if ($notify) {
            $this->notifyOpened($booking->fresh(['user', 'passengers', 'schedule.trip']), $tripCancelled);
        }

        return $booking;
    }

    /**
     * ลูกค้ากรอก/แก้บัญชีรับเงินคืน — ได้จนกว่าทีมงานจะเริ่มโอน
     *
     * @param  array<string, mixed>  $account  {bank, number, name}
     */
    public function submitAccount(Booking $booking, array $account): Booking
    {
        $account = self::normalizeAccount($account);

        return DB::transaction(function () use ($booking, $account) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->refund_status === self::PROCESSING) {
                throw new \Exception('ทีมงานกำลังโอนเงินคืนอยู่ หากต้องการเปลี่ยนบัญชีกรุณาติดต่อทีมงาน');
            }

            if (! in_array($locked->refund_status, self::EDITABLE_STATES, true)) {
                throw new \Exception('การจองนี้ไม่มีเรื่องคืนเงินที่รอบัญชีอยู่');
            }

            $locked->forceFill([
                'refund_account' => $account,
                'refund_status' => self::REQUESTED,
                'refund_account_at' => now(),
                'refund_note' => null,
            ])->save();

            return $locked;
        });
    }

    /** ทีมงานเริ่มโอน — ลูกค้าเห็นว่ากำลังดำเนินการ และแก้บัญชีเองไม่ได้แล้ว */
    public function markProcessing(Booking $booking): Booking
    {
        if ($booking->refund_status !== self::REQUESTED) {
            throw new \Exception($booking->refund_status === self::AWAITING_ACCOUNT
                ? 'ลูกค้ายังไม่ได้แจ้งบัญชีรับเงินคืน'
                : 'การจองนี้ไม่ได้อยู่ในขั้นรอโอน');
        }

        $booking->forceFill([
            'refund_status' => self::PROCESSING,
            'refund_processing_at' => now(),
        ])->save();

        $this->push(
            $booking,
            'refund_processing',
            'กำลังโอนเงินคืน',
            'ทีมงานกำลังโอนเงินคืน ฿'.number_format((float) $booking->refund_amount, 0)
                .($booking->refundAccountSummary() ? ' เข้า '.$booking->refundAccountSummary() : '')
                .' จะแจ้งอีกครั้งพร้อมสลิปเมื่อโอนเสร็จครับ',
        );

        return $booking;
    }

    /**
     * บัญชีโอนไม่ได้ (เลขผิด ชื่อไม่ตรง บัญชีปิด) — ขอให้ลูกค้ากรอกใหม่ พร้อมบอกเหตุ
     */
    public function rejectAccount(Booking $booking, string $note): Booking
    {
        $note = trim($note);

        if ($note === '') {
            throw new \Exception('กรุณาบอกลูกค้าว่าบัญชีมีปัญหาอะไร');
        }

        if (! in_array($booking->refund_status, [self::REQUESTED, self::PROCESSING], true)) {
            throw new \Exception('การจองนี้ยังไม่มีบัญชีให้ตรวจสอบ');
        }

        $booking->forceFill([
            'refund_status' => self::AWAITING_ACCOUNT,
            'refund_account' => null,
            'refund_account_at' => null,
            'refund_processing_at' => null,
            'refund_note' => mb_substr($note, 0, 500),
        ])->save();

        $this->push(
            $booking,
            'refund_account_rejected',
            'กรุณาตรวจสอบบัญชีรับเงินคืน',
            'โอนเงินคืนการจอง '.$booking->booking_ref.' ไม่สำเร็จ: '.$note.' กรุณากรอกบัญชีใหม่ในแอปครับ',
        );

        return $booking;
    }

    /** แจ้งลูกค้าว่าโอนเสร็จแล้ว (เรียกหลัง BookingService::processRefund) */
    public function notifyRefunded(Booking $booking): void
    {
        $account = $booking->refundAccountSummary();

        $this->push(
            $booking,
            'booking_refunded',
            'โอนเงินคืนแล้ว ฿'.number_format((float) $booking->refund_amount, 0),
            'การจอง '.$booking->booking_ref.' โอนเงินคืนแล้ว'
                .($account ? ' เข้า '.$account : '')
                .($booking->refund_slip_path ? ' ดูสลิปการโอนได้ในแอป' : '')
                .' ขอบคุณที่รอครับ',
        );
    }

    /**
     * เตือนคนที่ยังไม่กรอกบัญชี — งานรายวัน 10:00 เตือนวันที่ 2 และ 5 หลังเปิดเรื่อง
     * นับเป็นวันตามปฏิทินไทย จึงไม่ต้องจดว่าเตือนไปแล้ว (แต่ละใบตรงเงื่อนไขวันละครั้ง)
     */
    public function remindMissingAccounts(): int
    {
        $today = Carbon::now(self::TIMEZONE)->startOfDay();
        $count = 0;

        foreach (self::ACCOUNT_REMINDER_DAYS as $days) {
            $day = $today->copy()->subDays($days);

            $bookings = Booking::query()
                ->where('refund_status', self::AWAITING_ACCOUNT)
                ->whereNotNull('user_id')
                ->whereBetween('refund_opened_at', [
                    $day->copy()->utc(),
                    $day->copy()->endOfDay()->utc(),
                ])
                ->get();

            foreach ($bookings as $booking) {
                $this->push(
                    $booking,
                    'refund_account_reminder',
                    'ยังรอบัญชีรับเงินคืนอยู่นะครับ',
                    'การจอง '.$booking->booking_ref.' มีเงินคืน ฿'.number_format((float) $booking->refund_amount, 0)
                        .' รอโอนให้คุณ กรอกบัญชีธนาคารหรือพร้อมเพย์ในแอปได้เลย ใช้เวลาไม่ถึงนาทีครับ',
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * ข้อมูลคืนเงินสำหรับหน้าจอลูกค้า — null = ใบนี้ไม่มีเรื่องคืนเงิน
     *
     * @return array<string, mixed>|null
     */
    public static function customerPayload(Booking $booking): ?array
    {
        $state = self::stateOf($booking);

        if ($state === null) {
            return null;
        }

        $account = $booking->refund_account;
        $hasAccount = self::hasAccount($booking);
        $editable = in_array($state, self::EDITABLE_STATES, true);

        return [
            'state' => $state,
            'amount' => $booking->refund_amount !== null ? (float) $booking->refund_amount : null,
            'paid_amount' => (float) $booking->paid_amount,
            'full' => $booking->owesFullRefund(),
            'reason' => $booking->cancellation_reason,
            'account' => $hasAccount ? [
                'bank' => (string) ($account['bank'] ?? ''),
                'number_masked' => '••••'.substr((string) $account['number'], -4),
                'name' => (string) ($account['name'] ?? ''),
                'label' => $booking->refundAccountSummary(),
            ] : null,
            'can_edit_account' => $editable,
            'banks' => $editable ? self::BANKS : [],
            'note' => $booking->refund_note,
            'eta_label' => self::ETA_LABEL,
            'slip_url' => MediaDisk::slipUrl($booking->refund_slip_path),
            'cancelled_at' => $booking->cancelled_at?->toISOString(),
            'opened_at' => ($booking->refund_opened_at ?? $booking->cancelled_at)?->toISOString(),
            'account_at' => $booking->refund_account_at?->toISOString(),
            'processing_at' => $booking->refund_processing_at?->toISOString(),
            'refunded_at' => $booking->refunded_at?->toISOString(),
        ];
    }

    /**
     * ขั้นปัจจุบันของการคืนเงิน
     * ใบเก่าที่ refund_status = requested แต่ไม่มีบัญชี (คืนอัตโนมัติรอบคนไม่ครบ) = รอบัญชี
     */
    public static function stateOf(Booking $booking): ?string
    {
        $status = $booking->refund_status;

        if ($status === self::REFUNDED || ($status === null && $booking->status === 'refunded')) {
            return self::REFUNDED;
        }

        if ($status === self::REQUESTED && ! self::hasAccount($booking)) {
            return self::AWAITING_ACCOUNT;
        }

        return in_array($status, self::OPEN_STATES, true) ? $status : null;
    }

    public static function hasAccount(Booking $booking): bool
    {
        $account = $booking->refund_account;

        return is_array($account) && ! empty($account['number']);
    }

    /**
     * เลขบัญชีที่ลูกค้าพิมพ์มา — ตัดขีด/ช่องว่างออก เก็บแต่ตัวเลข
     *
     * @param  array<string, mixed>  $account
     * @return array{bank: string, number: string, name: string}
     */
    public static function normalizeAccount(array $account): array
    {
        $bank = trim((string) ($account['bank'] ?? ''));
        $number = preg_replace('/\D+/', '', (string) ($account['number'] ?? ''));
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($account['name'] ?? '')));

        if (! in_array($bank, self::BANKS, true)) {
            throw new \Exception('กรุณาเลือกธนาคารหรือพร้อมเพย์');
        }

        // พร้อมเพย์ = เบอร์มือถือ 10 หลัก หรือเลขบัตรประชาชน 13 หลัก
        if ($bank === 'พร้อมเพย์' ? ! in_array(strlen($number), [10, 13], true) : (strlen($number) < 9 || strlen($number) > 15)) {
            throw new \Exception($bank === 'พร้อมเพย์'
                ? 'พร้อมเพย์ต้องเป็นเบอร์มือถือ 10 หลัก หรือเลขบัตรประชาชน 13 หลัก'
                : 'เลขบัญชีไม่ถูกต้อง');
        }

        if ($name === '' || mb_strlen($name) > 120) {
            throw new \Exception('กรุณากรอกชื่อบัญชี');
        }

        return ['bank' => $bank, 'number' => $number, 'name' => $name];
    }

    private function notifyOpened(Booking $booking, bool $tripCancelled): void
    {
        $amount = number_format((float) $booking->refund_amount, 0);
        $trip = $booking->schedule?->trip?->title ?? 'ทริป';
        $needsAccount = $booking->refund_status === self::AWAITING_ACCOUNT;
        $whole = $booking->refund_full ? ' เต็มจำนวน' : '';

        $title = match (true) {
            $tripCancelled && $needsAccount => 'ทริปถูกยกเลิก · กรอกบัญชีรับเงินคืน',
            $tripCancelled => 'ทริปถูกยกเลิก · เราจะคืนเงินให้',
            $needsAccount => 'กรอกบัญชีเพื่อรับเงินคืน',
            default => 'รับเรื่องคืนเงินแล้ว',
        };

        $body = ($tripCancelled
                ? 'ขออภัยครับ '.$trip.' รอบ '.ThaiDate::short($booking->schedule?->departure_date)
                    .' ต้องยกเลิก'.($booking->cancellation_reason ? 'เนื่องจาก'.$booking->cancellation_reason : '').' '
                : 'การจอง '.$booking->booking_ref.' ')
            .'เราจะคืนเงิน ฿'.$amount.$whole
            .($needsAccount
                ? ' กรุณากรอกบัญชีธนาคารหรือพร้อมเพย์ในแอปเพื่อรับเงินคืน'
                : ' เข้า '.$booking->refundAccountSummary().' '.self::ETA_LABEL);

        $this->push($booking, $needsAccount ? 'refund_account_needed' : 'refund_requested', $title, $body);
        $this->mail->sendBookingCancelledEmail($booking, $booking->cancellation_reason);
        $this->sms->sendRefundOpened($booking, $tripCancelled);
    }

    private function push(Booking $booking, string $type, string $title, string $body): void
    {
        if (! $booking->user_id) {
            return;
        }

        try {
            SmartNotification::send(
                $booking->user_id,
                $type,
                $title,
                $body,
                [
                    'booking_ref' => $booking->booking_ref,
                    'refund_amount' => (float) $booking->refund_amount,
                    'refund_status' => self::stateOf($booking),
                    'route' => 'refund',
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('Refund push failed', [
                'booking_ref' => $booking->booking_ref,
                'type' => $type,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function notifyWaitlist(TripSchedule $schedule, Collection $entries, string $reason): void
    {
        foreach ($entries as $entry) {
            try {
                SmartNotification::send(
                    $entry->user_id,
                    'waitlist_round_cancelled',
                    'รอบที่คุณรอคิวถูกยกเลิก',
                    ($schedule->trip?->title ?? 'ทริป').' รอบ '.ThaiDate::short($schedule->departure_date)
                        .' ยกเลิกเนื่องจาก'.$reason.' ลองดูรอบอื่นของทริปนี้ได้เลย',
                    ['trip_slug' => $schedule->trip?->slug, 'schedule_id' => $schedule->id, 'route' => 'trip'],
                );
            } catch (\Throwable $e) {
                Log::warning('Cancel schedule waitlist push failed', ['message' => $e->getMessage()]);
            }
        }
    }

    private function notifyStaff(TripSchedule $schedule, string $reason, int $bookingCount): void
    {
        foreach ($schedule->activeStaff()->get() as $staff) {
            /** @var User $staff */
            try {
                SmartNotification::send(
                    $staff->id,
                    'schedule_cancelled',
                    'รอบเดินทางถูกยกเลิก',
                    ($schedule->trip?->title ?? 'ทริป').' รอบ '.ThaiDate::short($schedule->departure_date)
                        .' ยกเลิกเนื่องจาก'.$reason." ลูกค้า {$bookingCount} รายการได้รับแจ้งแล้ว ทีมงานจะโอนเงินคืนให้",
                    ['schedule_id' => $schedule->id],
                );
            } catch (\Throwable $e) {
                Log::warning('Cancel schedule staff push failed', ['message' => $e->getMessage()]);
            }
        }
    }
}
