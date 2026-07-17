<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\InstallmentPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InstallmentPaidMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public InstallmentPayment $installment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "ชำระงวดที่ {$this->installment->installment_no} สำเร็จ #{$this->booking->booking_ref} - Luilaykhao",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.installment-paid',
        );
    }
}
