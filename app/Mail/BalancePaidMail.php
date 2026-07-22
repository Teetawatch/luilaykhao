<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BalancePaidMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "ได้รับเงินครบแล้วครับ ขอบคุณมาก ๆ 💚 #{$this->booking->booking_ref} - Luilaykhao",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.balance-paid',
        );
    }
}
