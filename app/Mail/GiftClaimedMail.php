<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ส่งให้ผู้ให้ของขวัญเมื่อผู้รับกดรับแล้ว — ยืนยันว่าของขวัญถูกเปิดเรียบร้อย
 */
class GiftClaimedMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ของขวัญของคุณถูกเปิดแล้ว 🎉 #'.$this->booking->booking_ref.' - Luilaykhao',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.gift-claimed',
        );
    }
}
