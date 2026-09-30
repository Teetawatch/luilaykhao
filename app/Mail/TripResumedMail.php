<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ทีมงานย้อนการเลื่อนรอบ (กดผิดรอบ) — แก้อีเมล TripPostponedMail ที่ส่งไปก่อนหน้า
 */
class TripResumedMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        $title = $this->booking->schedule->trip->title ?? 'ทริป';

        return new Envelope(
            subject: "แก้ไข: ทริป {$title} เดินทางตามกำหนดเดิมครับ #{$this->booking->booking_ref}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.trip-resumed');
    }
}
