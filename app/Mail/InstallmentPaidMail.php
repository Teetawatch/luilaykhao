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
            subject: __('ยืนยันการรับชำระค่างวดที่ :no #:ref - Luilaykhao', ['no' => $this->installment->installment_no, 'ref' => $this->booking->booking_ref]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.installment-paid',
        );
    }
}
