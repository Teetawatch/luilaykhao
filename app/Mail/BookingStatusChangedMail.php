<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingStatusChangedMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public string $statusLabel;

    public function __construct(
        public Booking $booking,
        public string $newStatus,
    ) {
        $this->statusLabel = match ($newStatus) {
            'confirmed' => 'ยืนยันแล้ว',
            'cancelled' => 'ยกเลิกแล้ว',
            'refunded' => 'คืนเงินแล้ว',
            'pending' => 'รอดำเนินการ',
            default => $newStatus,
        };
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('แจ้งการเปลี่ยนแปลงสถานะการจอง #:ref - :status', ['ref' => $this->booking->booking_ref, 'status' => __($this->statusLabel)]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-status-changed',
        );
    }
}
