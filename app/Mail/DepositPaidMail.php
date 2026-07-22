<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DepositPaidMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "ได้รับมัดจำแล้วครับ ที่นั่งเป็นของคุณแล้ว 💚 #{$this->booking->booking_ref} - Luilaykhao",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.deposit-paid',
        );
    }
}
