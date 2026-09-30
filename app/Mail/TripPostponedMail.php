<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * รอบเดินทางถูกเลื่อนเพราะเหตุสุดวิสัย — บอกว่าเงินยังอยู่ครบ และพาไปเลือกรอบใหม่
 * รอบที่ไม่ออกเพราะคนไม่ครบใช้เทมเพลตของตัวเอง (เลือกรอบใหม่ หรือรับเงินคืนเต็มจำนวน)
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
            subject: $this->booking->isUnderfilledPostponement()
                ? "รอบเดินทาง {$title} ไม่ได้ออก — เลือกรอบใหม่หรือรับเงินคืนเต็มจำนวน #{$this->booking->booking_ref}"
                : "รอบเดินทาง {$title} ต้องเลื่อน — เลือกรอบใหม่ได้เลยครับ #{$this->booking->booking_ref}",
        );
    }

    public function content(): Content
    {
        // คนไม่ครบไม่ใช่เหตุสุดวิสัย — คนละเนื้อหา (มีทางเลือกรับเงินคืน + กำหนดตัดสินใจ)
        return new Content(view: $this->booking->isUnderfilledPostponement()
            ? 'emails.trip-underfilled-cancelled'
            : 'emails.trip-postponed');
    }
}
