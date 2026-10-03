<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\InstallmentPayment;
use App\Models\SmsLog;
use App\Support\ThaiDate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SmsService
{
    /** sms_type ของ SMS "รอบนี้คนไม่ครบ ขอคืนเงินได้" ที่ทีมงานกดส่งเอง */
    public const UNDERFILLED_NOTICE = 'trip_underfilled_notice';

    public function __construct(
        private ThaiBulkSmsClient $client,
    ) {}

    public function sendPaymentConfirmed(Booking $booking, string $paymentType = 'full'): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip']);

        // ข้อความนี้จะค้างอยู่ในเครื่องลูกค้าไปจนถึงวันเดินทาง จึงต้องตอบได้ด้วยตัวเอง
        // ว่าไปทริปไหน วันไหน และกดดูจุดนัดพบต่อได้ที่ไหน
        return $this->queueOrSend(
            booking: $booking,
            type: 'payment_confirmed',
            dedupeKey: $paymentType,
            message: sprintf(
                'ยืนยันที่นั่งแล้ว %s %s %s ขอบคุณครับ จุดนัดพบ/ติดตามรถ %s',
                $this->tripTitle($booking),
                $this->departureDate($booking),
                $booking->booking_ref,
                $this->trackUrl($booking),
            ),
        );
    }

    public function sendInstallmentPaid(Booking $booking, InstallmentPayment $installment): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'installmentPayments']);
        $nextInstallmentText = $this->nextInstallmentText($booking, $installment);

        return $this->queueOrSend(
            booking: $booking,
            type: 'installment_paid',
            dedupeKey: 'installment:'.$installment->installment_no,
            message: sprintf(
                'รับชำระงวดที่ %d จำนวน %s บาท สำหรับ booking %s แล้ว%s',
                $installment->installment_no,
                $this->money($installment->amount),
                $booking->booking_ref,
                $nextInstallmentText,
            ),
        );
    }

    public function sendBookingCancelled(Booking $booking, ?string $reason = null): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip']);

        $trimmedReason = $this->clip($reason, 60);
        $reasonText = $trimmedReason !== '' ? " เหตุผล: {$trimmedReason}" : '';

        return $this->queueOrSend(
            booking: $booking,
            type: 'booking_cancelled',
            dedupeKey: 'default',
            message: sprintf(
                'หมายเลขการจอง %s ทริป %s ถูกยกเลิก%s ติดต่อทีมงานหากต้องการความช่วยเหลือ',
                $booking->booking_ref,
                $this->tripTitle($booking),
                $reasonText,
            ),
        );
    }

    /**
     * รอบถูกเลื่อนเพราะเหตุสุดวิสัย — ข่าวที่ลูกค้าต้องรู้แม้ไม่มีแอปและไม่ได้ผูก LINE
     * (เคยจ่ายเงินไปแล้ว ถ้าไม่รู้ก็จะมายืนรอรถที่ไม่มา)
     */
    public function sendTripPostponed(Booking $booking): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip']);

        return $this->queueOrSend(
            booking: $booking,
            type: 'trip_postponed',
            // ผูกกับครั้งที่เลื่อน — ย้อนแล้วเลื่อนใหม่ (หรือรอบใหม่ถูกเลื่อนซ้ำ) ต้องส่งได้อีก
            dedupeKey: 's'.($booking->force_majeure_schedule_id ?? $booking->schedule_id)
                .':'.$booking->force_majeure_at?->timestamp,
            message: $booking->isUnderfilledPostponement()
                ? sprintf(
                    'ทริป %s %s ไม่ได้ออกเดินทางเนื่องจาก%s เลือกรอบใหม่ฟรีหรือรับเงินคืนเต็มจำนวน ภายใน %s %s',
                    $this->tripTitle($booking),
                    ThaiDate::short($booking->schedule?->departure_date),
                    $this->clip($booking->force_majeure_reason, 40),
                    ThaiDate::short($booking->postpone_decide_by),
                    ForceMajeureService::chooseUrl($booking),
                )
                : sprintf(
                    'ทริป %s %s ต้องเลื่อนเนื่องจาก%s ยอดที่ชำระยังอยู่ครบ เลือกรอบใหม่ได้ฟรีถึง %s %s',
                    $this->tripTitle($booking),
                    ThaiDate::short($booking->schedule?->departure_date),
                    $this->clip($booking->force_majeure_reason, 40),
                    ThaiDate::short($booking->force_majeure_until),
                    ForceMajeureService::chooseUrl($booking),
                ),
        );
    }

    /**
     * รอบไม่ได้ออกเพราะคนไม่ครบ — ลูกค้าขอคืนเงิน (หรือเลยกำหนดแล้วระบบคืนให้)
     * แยกจาก booking_cancelled เพราะต้องบอกยอดคืนเต็มจำนวน ไม่ใช่นโยบายยกเลิกปกติ
     */
    public function sendUnderfilledRefund(Booking $booking): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip']);
        $amount = (float) ($booking->refund_amount ?? 0);

        return $this->queueOrSend(
            booking: $booking,
            type: 'trip_refund_requested',
            dedupeKey: 'fm:'.$booking->force_majeure_at?->timestamp,
            message: $amount > 0
                ? sprintf(
                    'การจอง %s ยกเลิกแล้ว เราจะคืนเงิน %s บาทเต็มจำนวน%s',
                    $booking->booking_ref,
                    number_format($amount, 0),
                    $booking->refundAccountSummary()
                        ? ' เข้าบัญชีที่แจ้งไว้ภายใน 3-7 วันทำการ'
                        : ' ทีมงานจะติดต่อขอเลขบัญชีรับเงินคืน',
                )
                : sprintf('การจอง %s ยกเลิกแล้ว ไม่มียอดที่ต้องคืน', $booking->booking_ref),
        );
    }

    /**
     * ทีมงานย้อนการเลื่อน (กดผิดรอบ) — คนที่ได้ SMS ว่ารอบเลื่อนไปแล้วต้องได้ SMS แก้
     */
    public function sendTripResumed(Booking $booking, int $postponedAt): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip']);

        return $this->queueOrSend(
            booking: $booking,
            type: 'trip_resumed',
            dedupeKey: 'fm:'.$postponedAt,
            message: sprintf(
                'ขออภัยครับ ทริป %s %s เดินทางตามกำหนดเดิม ข้อความเลื่อนรอบก่อนหน้าส่งผิด ไม่ต้องเลือกรอบใหม่ (%s)',
                $this->tripTitle($booking),
                ThaiDate::short($booking->schedule?->departure_date),
                $booking->booking_ref,
            ),
        );
    }

    public function sendTripPostponedReminder(Booking $booking): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip']);

        return $this->queueOrSend(
            booking: $booking,
            type: 'trip_postponed_reminder',
            dedupeKey: $booking->isUnderfilledPostponement()
                ? 'decide:'.$booking->postpone_decide_by?->toDateString()
                : 'until:'.$booking->force_majeure_until?->toDateString(),
            message: $booking->isUnderfilledPostponement()
                ? sprintf(
                    'การจอง %s ยังไม่ได้เลือกรอบใหม่ เลือกรอบฟรีหรือรับเงินคืนได้ถึง %s ถ้าไม่เลือกเราจะคืนเงินให้ %s',
                    $booking->booking_ref,
                    ThaiDate::short($booking->postpone_decide_by),
                    ForceMajeureService::chooseUrl($booking),
                )
                : sprintf(
                    'การจอง %s ยังไม่ได้เลือกรอบใหม่ เลือกได้ถึง %s ราคาเดิม %s',
                    $booking->booking_ref,
                    ThaiDate::short($booking->force_majeure_until),
                    ForceMajeureService::chooseUrl($booking),
                ),
        );
    }

    /**
     * แจ้งลูกค้าของรอบที่คนยังไม่ครบ ว่าขอเงินคืนเต็มจำนวนได้ทางไลน์ — ทีมงานกดส่งเอง
     * จากหน้ารอบเสี่ยงไม่ออก ข้อความทั้งก้อนมาจากหน้าแอดมิน (แก้ได้ก่อนส่ง)
     *
     * ผูกกับรอบ: ส่งให้ใบจองเดิมในรอบเดิมได้ครั้งเดียว กดซ้ำไม่เปลืองเครดิตซ้ำ
     */
    public function sendUnderfilledNotice(Booking $booking, string $message): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip']);

        return $this->queueOrSend(
            booking: $booking,
            type: self::UNDERFILLED_NOTICE,
            dedupeKey: self::underfilledNoticeKey($booking->schedule_id),
            message: trim($message),
        );
    }

    public static function underfilledNoticeKey(int $scheduleId): string
    {
        return 's'.$scheduleId;
    }

    public function sendInstallmentReminder(InstallmentPayment $installment, string $reminderType): ?SmsLog
    {
        $installment->loadMissing(['booking.user', 'booking.passengers']);
        $booking = $installment->booking;

        if (! $booking) {
            return null;
        }

        $prefix = match ($reminderType) {
            'due_soon' => 'ใกล้ครบกำหนดชำระในอีก 2 วัน',
            'due_today' => 'ครบกำหนดชำระวันนี้',
            'overdue' => 'เลยกำหนดชำระแล้ว',
            default => 'แจ้งเตือนชำระเงิน',
        };

        return $this->queueOrSend(
            booking: $booking,
            type: 'installment_'.$reminderType,
            dedupeKey: 'installment:'.$installment->installment_no.':'.$installment->due_date?->toDateString(),
            message: sprintf(
                '%s งวดที่ %d จำนวน %s บาท booking %s กำหนด %s ชำระที่ %s',
                $prefix,
                $installment->installment_no,
                $this->money($installment->amount),
                $booking->booking_ref,
                ThaiDate::short($installment->due_date),
                $this->payUrl($booking),
            ),
        );
    }

    public function sendDepositPaid(Booking $booking): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip']);

        $balance = (float) ($booking->balance_amount ?? 0);
        $due = ThaiDate::short($booking->balance_due_at);

        return $this->queueOrSend(
            booking: $booking,
            type: 'deposit_paid',
            dedupeKey: 'default',
            message: sprintf(
                'รับมัดจำ %s บาทแล้ว %s ยอดคงเหลือ %s บาท ครบกำหนด %s ชำระที่ %s',
                $this->money($booking->deposit_amount),
                $booking->booking_ref,
                $this->money($balance),
                $due,
                $this->payUrl($booking),
            ),
        );
    }

    public function sendBalanceDueReminder(Booking $booking): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip']);

        $due = ThaiDate::short($booking->balance_due_at);

        return $this->queueOrSend(
            booking: $booking,
            type: 'balance_due_reminder',
            dedupeKey: $booking->balance_due_at?->toDateString() ?? 'default',
            message: sprintf(
                'เตือนชำระยอดคงเหลือ %s บาท %s ภายใน %s ชำระที่ %s',
                $this->money($booking->balance_amount),
                $booking->booking_ref,
                $due,
                $this->payUrl($booking),
            ),
        );
    }

    public function sendBalancePaid(Booking $booking): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip']);

        return $this->queueOrSend(
            booking: $booking,
            type: 'balance_paid',
            dedupeKey: 'default',
            message: sprintf(
                'รับชำระครบแล้ว %s บาท %s ขอบคุณครับ ดูจุดนัดพบ/ติดตามรถ %s',
                $this->money($booking->balance_amount),
                $booking->booking_ref,
                $this->trackUrl($booking),
            ),
        );
    }

    public function sendDepartureReminder(Booking $booking, int $daysBefore): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip', 'pickupPoint']);

        return $this->queueOrSend(
            booking: $booking,
            type: 'departure_reminder',
            // ใบที่ย้ายรอบมาแล้วต้องได้เตือนของรอบใหม่ด้วย — ใบที่ไม่เคยย้ายคงคีย์เดิมไว้
            dedupeKey: $daysBefore.'_days_before'
                .($booking->roundChangedAt() ? ':s'.$booking->schedule_id : ''),
            message: sprintf(
                'อีก %d วันถึงทริป %s %s จุดนัดพบ %s ติดตามรถ %s',
                $daysBefore,
                $this->tripTitle($booking),
                $this->departureDate($booking),
                $this->meetingPoint($booking),
                $this->trackUrl($booking),
            ),
        );
    }

    /**
     * ลิงก์ "เปิดใช้บัญชี" สำหรับใบจองที่ทีมงานเปิดให้แทนลูกค้า — ลูกค้ายังไม่มี
     * รหัสผ่าน จึงเข้าแอปมาดูใบจองของตัวเองไม่ได้จนกว่าจะกดลิงก์นี้
     *
     * ส่งทาง SMS เท่านั้นโดยตั้งใจ: บัญชีแบบนี้ส่วนใหญ่ไม่มีอีเมลจริงในระบบ และ
     * การที่ลิงก์ไปโผล่ในเครื่องที่ถือเบอร์นั้นอยู่ คือสิ่งที่ยืนยันว่าเป็นเจ้าของจริง
     */
    public function sendAccountClaimLink(Booking $booking, string $url, string $dedupeKey = 'default'): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip']);

        return $this->queueOrSend(
            booking: $booking,
            type: 'account_claim',
            dedupeKey: $dedupeKey,
            message: sprintf(
                'ทีมงานเปิดการจอง %s ทริป %s ให้คุณแล้ว เปิดใช้บัญชีเพื่อดูในแอป %s',
                $booking->booking_ref,
                $this->tripTitle($booking),
                $url,
            ),
        );
    }

    /**
     * ลิงก์ "ใบเดินทาง" ก่อนถึงวันเดินทาง
     *
     * ช่องทางหลักของลูกค้าที่ทีมงานเปิดใบจองแทนให้ — บัญชีกลุ่มนี้ส่วนใหญ่ไม่มี
     * อีเมลจริงในระบบ (ได้ที่อยู่ปลอม manual_...@luilaykhao.com ติดตัวมา) และ
     * ไม่ได้โหลดแอป SMS หนึ่งข้อความที่พาไปหน้าเดียวจึงเป็นทางเดียวที่ถึงตัวจริง
     *
     * [$dedupeKey] แยกฉบับแรกออกจากฉบับ "อัปเดต" เมื่อข้อมูลรอบเปลี่ยน
     */
    public function sendTripBrief(Booking $booking, string $url, string $dedupeKey = 'default'): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip']);

        return $this->queueOrSend(
            booking: $booking,
            type: 'trip_brief',
            dedupeKey: $dedupeKey,
            message: sprintf(
                'ใบเดินทาง %s %s จุดขึ้นรถ กำหนดการ และเบอร์ทีมงานดูได้ที่ %s',
                $this->tripTitle($booking),
                $this->departureDate($booking),
                $url,
            ),
        );
    }

    public function sendPending(int $limit = 100): int
    {
        if (! $this->isConfigured()) {
            Log::info('SMS pending send skipped: ThaiBulkSMS is not enabled or configured.');

            return 0;
        }

        $sent = 0;

        SmsLog::whereIn('status', ['pending', 'failed'])
            ->whereIn('sms_type', $this->sendableSmsTypes())
            ->where('attempts', '<', 3)
            ->where(function ($query) {
                $query->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', now());
            })
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get()
            ->each(function (SmsLog $log) use (&$sent) {
                if ($this->dispatch($log)) {
                    $sent++;
                }
            });

        return $sent;
    }

    private function queueOrSend(Booking $booking, string $type, string $dedupeKey, string $message): ?SmsLog
    {
        try {
            if (! in_array($type, $this->sendableSmsTypes(), true)) {
                return SmsLog::firstOrCreate(
                    [
                        'booking_id' => $booking->id,
                        'provider' => 'thaibulksms',
                        'sms_type' => $type,
                        'dedupe_key' => $dedupeKey,
                    ],
                    [
                        'recipient' => null,
                        'message' => $message,
                        'status' => 'skipped',
                        'error_message' => 'SMS type is disabled.',
                        'scheduled_at' => now(),
                    ],
                );
            }

            $recipient = $this->recipientFor($booking);

            if (! $recipient) {
                return SmsLog::firstOrCreate(
                    [
                        'booking_id' => $booking->id,
                        'provider' => 'thaibulksms',
                        'sms_type' => $type,
                        'dedupe_key' => $dedupeKey,
                    ],
                    [
                        'recipient' => null,
                        'message' => $message,
                        'status' => 'skipped',
                        'error_message' => 'No customer phone number available.',
                        'scheduled_at' => now(),
                    ],
                );
            }

            $log = SmsLog::firstOrCreate(
                [
                    'booking_id' => $booking->id,
                    'provider' => 'thaibulksms',
                    'sms_type' => $type,
                    'dedupe_key' => $dedupeKey,
                ],
                [
                    'recipient' => $recipient,
                    'message' => Str::limit($message, 450, ''),
                    'status' => 'pending',
                    'scheduled_at' => now(),
                ],
            );

            if ($log->wasRecentlyCreated && $this->isConfigured()) {
                $this->dispatch($log);
            }

            return $log;
        } catch (\Throwable $e) {
            Log::error('Failed to queue SMS', [
                'booking_id' => $booking->id,
                'sms_type' => $type,
                'dedupe_key' => $dedupeKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function dispatch(SmsLog $log): bool
    {
        try {
            $result = $this->client->send($log->recipient, $log->message);
            $providerId = data_get($result, 'body.id')
                ?? data_get($result, 'body.message_id')
                ?? data_get($result, 'body.data.0.id');

            $log->update([
                'status' => $result['ok'] ? 'sent' : 'failed',
                'attempts' => $log->attempts + 1,
                'request_payload' => $result['payload'],
                'response_payload' => $result['body'],
                'provider_message_id' => $providerId,
                'sent_at' => $result['ok'] ? now() : null,
                'failed_at' => $result['ok'] ? null : now(),
                'error_message' => $result['ok'] ? null : 'ThaiBulkSMS returned HTTP '.$result['status'],
            ]);

            return $result['ok'];
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'attempts' => $log->attempts + 1,
                'failed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Failed to send SMS', [
                'sms_log_id' => $log->id,
                'booking_id' => $log->booking_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function isConfigured(): bool
    {
        $config = config('services.thaibulksms');

        return (bool) $config['enabled']
            && filled($config['api_key'])
            && filled($config['api_secret'])
            && filled($config['sender']);
    }

    /**
     * ชนิดข้อความที่ยิงจริง — ที่ไม่อยู่ในลิสต์จะถูกบันทึกเป็น skipped เฉย ๆ
     *
     * `booking_created` ตั้งใจไม่ส่ง: ตอนเพิ่งกดจองลูกค้ายังอยู่หน้าจอ และมีทั้ง
     * อีเมลกับ push อยู่แล้ว ไม่คุ้มค่าเครดิต SMS
     */
    private function sendableSmsTypes(): array
    {
        return [
            'payment_confirmed',
            'installment_paid',
            'installment_due_soon',
            'installment_due_today',
            'installment_overdue',
            'booking_cancelled',
            'departure_reminder',
            'deposit_paid',
            'balance_due_reminder',
            'balance_paid',
            'account_claim',
            'trip_brief',
            'trip_postponed',
            'trip_postponed_reminder',
            'trip_resumed',
            'trip_refund_requested',
            self::UNDERFILLED_NOTICE,
        ];
    }

    public function recipientFor(Booking $booking): ?string
    {
        $firstPassenger = $booking->passengers->first();
        $phone = $firstPassenger ? $firstPassenger->phone : $booking->user?->phone;
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '66'.substr($digits, 1);
        }

        if (str_starts_with($digits, '66')) {
            return $digits;
        }

        return $digits;
    }

    private function tripTitle(Booking $booking): string
    {
        return $this->clip($booking->schedule?->trip?->title, 45) ?: 'ทริปของคุณ';
    }

    private function departureDate(Booking $booking): string
    {
        // แสดงวัน-เวลาออกรถจริง (เช่น 12/06/2026 23:30 น.) ถ้ารอบนั้นกำหนดไว้
        return $booking->schedule?->departureLabelShort() ?? '-';
    }

    private function meetingPoint(Booking $booking): string
    {
        $name = $booking->pickupPoint?->name
            ?? $booking->schedule?->trip?->departure_point;

        return $this->clip($name, 45) ?: 'ตามรายละเอียดการจอง';
    }

    /**
     * ลิงก์หน้าติดตามรถสาธารณะ /track/{token} — เปิดได้โดยไม่ต้องล็อกอิน และก่อน
     * วันเดินทางก็ยังบอกชื่อทริป วันเดินทาง และจุดนัดพบให้อยู่
     */
    private function trackUrl(Booking $booking): string
    {
        return $booking->shareUrl();
    }

    /** ลิงก์หน้าชำระเงินสาธารณะ /pay/{token} — ตัวเดียวกับที่อีเมลแจ้งยอดค้างใช้ */
    private function payUrl(Booking $booking): string
    {
        return $booking->payUrl();
    }

    /**
     * ตัดข้อความอิสระ (ชื่อทริป จุดนัดพบ เหตุผลยกเลิกที่แอดมินพิมพ์เอง) ให้สั้นพอ
     * ไม่งั้นมันจะดันลิงก์ท้ายข้อความให้ตกขอบตอน [queueOrSend] ตัดที่ 450 ตัวอักษร
     */
    private function clip(?string $value, int $max): string
    {
        return Str::limit(trim((string) $value), $max, '');
    }

    /** 1500 -> "1,500" แต่ 1500.50 -> "1,500.50" — ทศนิยมลอย ๆ กินเครดิต SMS เปล่า ๆ */
    private function money(mixed $amount): string
    {
        $value = (float) $amount;

        return number_format($value, fmod($value, 1) === 0.0 ? 0 : 2);
    }

    private function nextInstallmentText(Booking $booking, ?InstallmentPayment $currentInstallment = null): string
    {
        $installments = $booking->installmentPayments;

        if ($installments->isEmpty()) {
            return '';
        }

        $nextInstallment = $installments
            ->filter(function (InstallmentPayment $installment) use ($currentInstallment) {
                if ($installment->status === 'paid') {
                    return false;
                }

                return ! $currentInstallment
                    || $installment->installment_no > $currentInstallment->installment_no;
            })
            ->sortBy('installment_no')
            ->first();

        if (! $nextInstallment) {
            return ' ชำระครบทุกงวดแล้ว';
        }

        return sprintf(
            ' งวดถัดไปงวดที่ %d จำนวน %s บาท กำหนด %s ชำระที่ %s',
            $nextInstallment->installment_no,
            $this->money($nextInstallment->amount),
            ThaiDate::short($nextInstallment->due_date),
            $this->payUrl($booking),
        );
    }
}
