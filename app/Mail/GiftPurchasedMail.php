<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ส่งให้ผู้ซื้อของขวัญหลังสร้างการจอง — แนบโค้ดและลิงก์แชร์ไว้ส่งต่อให้ผู้รับ
 */
class GiftPurchasedMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ของขวัญของคุณพร้อมแล้ว 🎁 #'.$this->booking->booking_ref.' - Luilaykhao',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.gift-purchased',
            with: [
                'giftUrl' => $this->booking->giftUrl(),
            ],
        );
    }
}
