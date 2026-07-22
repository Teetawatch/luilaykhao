<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\InstallmentPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InstallmentDueReminderMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public InstallmentPayment $installment,
        public string $reminderType, // 'due_soon' | 'due_today' | 'overdue'
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->reminderType) {
            'overdue' => "ค่างวดที่ {$this->installment->installment_no} เลยกำหนดมานิดนึงแล้วครับ #{$this->booking->booking_ref}",
            'due_today' => "วันนี้ครบกำหนดค่างวดที่ {$this->installment->installment_no} นะครับ #{$this->booking->booking_ref}",
            default => "เตือนกันลืม ค่างวดที่ {$this->installment->installment_no} ใกล้ครบกำหนดแล้วครับ #{$this->booking->booking_ref}",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.installment-due-reminder');
    }
}
