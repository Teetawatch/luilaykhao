<?php

namespace App\Mail;

use App\Models\CustomerIntake;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * แจ้งทีมงานว่ามีลูกค้ากรอกข้อมูลผ่านลิงก์เข้ามาแล้วรอเปิดการจอง
 *
 * $reason บอกว่าทำไมถึงส่งตอนนี้
 *  - complete: กรอกครบทุกคนตามที่แจ้งไว้ = หยิบไปเปิดการจองได้เลย
 *  - stalled:  กรอกไม่ครบและเงียบไปแล้ว = ต้องมีคนไปตามในแชท
 */
class AdminIntakeReadyMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public CustomerIntake $intake,
        public string $reason = 'complete',
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->intake->contact_name ?: 'ลูกค้า';
        $subject = $this->reason === 'stalled'
            ? 'ลูกค้ากรอกค้างไว้ — '.$name
            : 'ลูกค้ากรอกข้อมูลครบแล้ว — '.$name;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin-intake-ready');
    }
}
