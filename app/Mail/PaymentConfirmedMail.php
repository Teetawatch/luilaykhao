<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentConfirmedMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public string $paymentType = 'full',
    ) {}

    public function envelope(): Envelope
    {
        $typeLabel = $this->paymentType === 'installment' ? '(งวดแรก)' : '';
        return new Envelope(
            subject: "✅ ชำระเงินสำเร็จ {$typeLabel} #{$this->booking->booking_ref} - Luilaykhao",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-confirmed',
        );
    }
}
