<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\Receipt;
use App\Support\ReceiptAttachment;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BalancePaidMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public ?Receipt $receipt = null,
        // ใบแยกรายบุคคล: ผู้จองได้ของทุกคน (เป็นลิงก์), เพื่อนได้ของตัวเอง
        public ?EloquentCollection $personalReceipts = null,
        // ชื่อในคำทักทาย เมื่อผู้รับไม่ใช่ผู้จอง (เพื่อนที่ได้ใบของตัวเอง)
        public ?string $recipientName = null,
    ) {}

    public function attachments(): array
    {
        return ReceiptAttachment::forEmail($this->receipt, $this->personalReceipts);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('ยืนยันการชำระเงินครบถ้วน #:ref - Luilaykhao', ['ref' => $this->booking->booking_ref]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.balance-paid',
        );
    }
}
