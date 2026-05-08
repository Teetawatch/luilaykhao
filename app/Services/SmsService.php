<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\InstallmentPayment;
use App\Models\SmsLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SmsService
{
    public function __construct(
        private ThaiBulkSmsClient $client,
    ) {}

    public function sendBookingCreated(Booking $booking): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip']);

        return $this->queueOrSend(
            booking: $booking,
            type: 'booking_created',
            dedupeKey: 'default',
            message: sprintf(
                'จองทริป %s สำเร็จ รหัส %s กรุณาชำระเงินเพื่อยืนยันที่นั่ง รายละเอียด %s',
                $this->tripTitle($booking),
                $booking->booking_ref,
                $this->bookingUrl($booking),
            ),
        );
    }

    public function sendPaymentConfirmed(Booking $booking, string $paymentType = 'full'): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip', 'installmentPayments']);

        $label = $paymentType === 'installment' ? 'รับชำระงวดแรกแล้ว' : 'รับชำระเงินแล้ว';
        $nextInstallmentText = $paymentType === 'installment'
            ? $this->nextInstallmentText($booking)
            : '';

        return $this->queueOrSend(
            booking: $booking,
            type: 'payment_confirmed',
            dedupeKey: $paymentType,
            message: sprintf(
                '%s สำหรับ booking %s ทริป %s วันที่ %s%s',
                $label,
                $booking->booking_ref,
                $this->tripTitle($booking),
                $this->departureDate($booking),
                $nextInstallmentText,
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
            dedupeKey: 'installment:' . $installment->installment_no,
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

        $reasonText = $reason ? " เหตุผล: {$reason}" : '';

        return $this->queueOrSend(
            booking: $booking,
            type: 'booking_cancelled',
            dedupeKey: 'default',
            message: sprintf(
                'booking %s ทริป %s ถูกยกเลิกแล้ว%s ติดต่อทีมงานหากต้องการความช่วยเหลือ',
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
            type: 'installment_' . $reminderType,
            dedupeKey: 'installment:' . $installment->installment_no . ':' . $installment->due_date?->toDateString(),
            message: sprintf(
                '%s งวดที่ %d จำนวน %s บาท booking %s กำหนด %s ชำระที่ %s',
                $prefix,
                $installment->installment_no,
                $this->money($installment->amount),
                $booking->booking_ref,
                $installment->due_date?->format('d/m/Y'),
                $this->bookingUrl($booking),
            ),
        );
    }

    public function sendDepartureReminder(Booking $booking, int $daysBefore): ?SmsLog
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip', 'pickupPoint']);

        return $this->queueOrSend(
            booking: $booking,
            type: 'departure_reminder',
            dedupeKey: $daysBefore . '_days_before',
            message: sprintf(
                'อีก %d วันถึงทริป %s วันที่ %s จุดนัดพบ %s รายละเอียด %s',
                $daysBefore,
                $this->tripTitle($booking),
                $this->departureDate($booking),
                $this->meetingPoint($booking),
                $this->bookingUrl($booking),
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
                'error_message' => $result['ok'] ? null : 'ThaiBulkSMS returned HTTP ' . $result['status'],
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

    private function sendableSmsTypes(): array
    {
        return [
            'booking_created',
            'payment_confirmed',
            'installment_paid',
            'installment_due_soon',
            'installment_due_today',
            'installment_overdue',
            'booking_cancelled',
            'departure_reminder',
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
            return '66' . substr($digits, 1);
        }

        if (str_starts_with($digits, '66')) {
            return $digits;
        }

        return $digits;
    }

    private function tripTitle(Booking $booking): string
    {
        return $booking->schedule?->trip?->title ?? 'ทริปของคุณ';
    }

    private function departureDate(Booking $booking): string
    {
        return $booking->schedule?->departure_date?->format('d/m/Y') ?? '-';
    }

    private function meetingPoint(Booking $booking): string
    {
        return $booking->pickupPoint?->name
            ?? $booking->schedule?->trip?->departure_point
            ?? 'ตามรายละเอียดการจอง';
    }

    private function bookingUrl(Booking $booking): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/confirmation/' . $booking->booking_ref;
    }

    private function installmentPaymentUrl(Booking $booking): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/installment-payment/' . $booking->booking_ref;
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2);
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
            $nextInstallment->due_date?->format('d/m/Y') ?? '-',
            $this->installmentPaymentUrl($booking),
        );
    }
}
