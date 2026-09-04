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
            dedupeKey: $daysBefore.'_days_before',
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

    private function isConfigured(): bool
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
        ];
    }

    private function recipientFor(Booking $booking): ?string
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
