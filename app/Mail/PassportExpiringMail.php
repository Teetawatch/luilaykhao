<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * พาสปอร์ตที่กรอกไว้แล้วจะหมดอายุเร็วกว่าเกณฑ์ 6 เดือนนับจากวันเดินทาง
 *
 * คนละเรื่องกับ PassportInfoNeededMail: ฉบับนั้นคือ "ยังไม่ได้กรอก" ฉบับนี้คือ
 * "กรอกครบแล้วแต่เล่มใช้เดินทางรอบนี้ไม่ได้" ซึ่งเป็นปัญหาที่โผล่ขึ้นมาเองเมื่อ
 * เวลาผ่านไป — ตอนจองยังผ่านเกณฑ์ แต่เลื่อนรอบหรือจองล่วงหน้านานจนตกเกณฑ์
 * ต้องบอกให้ทันทำเล่มใหม่ ไม่ใช่ไปรู้ตัวที่เคาน์เตอร์เช็คอิน
 */
class PassportExpiringMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public string $passportUrl,
        public int $daysUntilDeparture,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'พาสปอร์ตใกล้หมดอายุ ต้องต่อเล่มก่อนเดินทาง 🛂 #'.$this->booking->booking_ref.' - Luilaykhao',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.passport-expiring',
        );
    }
}
