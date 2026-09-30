<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * รอบเดินทางถูกเลื่อนเพราะเหตุสุดวิสัย — บอกว่าเงินยังอยู่ครบ และพาไปเลือกรอบใหม่
 * (ดู ForceMajeureService)
 */
class TripPostponedMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        $title = $this->booking->schedule->trip->title ?? 'ทริป';

        return new Envelope(
            subject: "รอบเดินทาง {$title} ต้องเลื่อน — เลือกรอบใหม่ได้เลยครับ #{$this->booking->booking_ref}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.trip-postponed');
    }
}
