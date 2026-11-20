<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ขอข้อมูลพาสปอร์ตหลังจองทริปต่างประเทศ
 *
 * ส่งเมื่อการจองเข้ามาจากช่องทางที่ยังไม่มีช่องกรอกเอกสารเดินทาง (แอปรุ่นก่อน
 * หน้าที่ลูกค้าอีกส่วนยังใช้อยู่) ลิงก์ในอีเมลพาไปกรอกบนเว็บได้เลยโดยไม่ต้อง
 * อัปเดตแอปและไม่ต้องล็อกอิน
 */
class PassportInfoNeededMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public string $passportUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('ขอข้อมูลหนังสือเดินทางของผู้เดินทาง #:ref - Luilaykhao', ['ref' => $this->booking->booking_ref]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.passport-needed',
        );
    }
}
