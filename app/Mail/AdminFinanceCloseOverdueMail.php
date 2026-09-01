<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * เตือนทีมงานว่ามีรอบเดินทางจบแล้วแต่ยังไม่ปิดงบ
 *
 * ส่งรวมเป็นฉบับเดียวต่อวัน ไม่ใช่ฉบับละรอบ — ค้าง 12 รอบไม่ควรกลายเป็นเมล 12 ฉบับ
 * ที่ทุกคนเลิกอ่านตั้งแต่ฉบับที่สาม
 */
class AdminFinanceCloseOverdueMail extends QueuedMail
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $rounds  ผลจาก ScheduleFinanceService::overdueRounds()
     */
    public function __construct(
        public array $rounds,
        public int $graceDays,
        public bool $blocksNewRounds,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ค้างปิดงบ '.count($this->rounds).' รอบเดินทาง',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin-finance-close-overdue');
    }
}
