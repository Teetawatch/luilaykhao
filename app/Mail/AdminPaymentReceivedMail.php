<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminPaymentReceivedMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public string $paymentType = 'full',
    ) {}

    public function envelope(): Envelope
    {
        $typeLabel = match ($this->paymentType) {
            'installment' => '(ผ่อนชำระ)',
            'deposit' => '(มัดจำ)',
            'balance' => '(ส่วนที่เหลือ)',
            default => '(เต็มจำนวน)',
        };

        return new Envelope(
            subject: "ได้รับชำระเงิน {$typeLabel} #{$this->booking->booking_ref} - ฿".number_format($this->booking->paid_amount, 0),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-payment-received',
        );
    }
}
