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
        $title = $this->booking->schedule?->trip?->tr('title') ?? __('ทริป');

        return new Envelope(
            subject: $this->booking->isUnderfilledPostponement()
                ? __('แจ้งงดการเดินทางรอบ :title — เลือกรอบใหม่หรือรับเงินคืนเต็มจำนวน #:ref', ['title' => $title, 'ref' => $this->booking->booking_ref])
                : __('แจ้งเลื่อนรอบเดินทาง :title — กรุณาเลือกรอบใหม่ #:ref', ['title' => $title, 'ref' => $this->booking->booking_ref]),
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
