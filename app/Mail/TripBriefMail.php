<?php

namespace App\Mail;

use App\Models\Booking;
use App\Services\TripBriefService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "ใบเดินทาง" ฉบับอีเมล — เนื้อหาเดียวกับหน้า /t/{token} เป๊ะ ๆ เพราะวาดจาก
 * payload ก้อนเดียวกัน (TripBriefService) ต่างกันแค่อีเมลนิ่งอยู่กับที่ ส่วน
 * หน้าเว็บอัปเดตตัวเองต่อไปได้ — ทุกฉบับจึงจบด้วยลิงก์ไปหน้านั้นเสมอ
 *
 * [$isUpdate] คือฉบับที่ส่งตามหลังเพราะข้อมูลรอบเปลี่ยน (เปลี่ยนคนขับ เพิ่มสตาฟ
 * ขยับกำหนดการ) พาดหัวและคำขึ้นต้นจะบอกให้ชัดว่านี่ไม่ใช่อีเมลซ้ำ
 */
class TripBriefMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public bool $isUpdate = false,
    ) {}

    public function envelope(): Envelope
    {
        $title = $this->booking->schedule?->trip?->title ?? 'ทริปของคุณ';

        return new Envelope(
            subject: $this->isUpdate
                ? "อัปเดตใบเดินทาง {$title} #{$this->booking->booking_ref} - Luilaykhao"
                : "ใบเดินทาง {$title} #{$this->booking->booking_ref} - Luilaykhao",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.trip-brief',
            with: [
                'booking' => $this->booking,
                'isUpdate' => $this->isUpdate,
                'b' => app(TripBriefService::class)->payload($this->booking),
            ],
        );
    }
}
