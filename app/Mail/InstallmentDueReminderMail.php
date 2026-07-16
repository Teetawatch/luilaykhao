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
            'overdue'   => "⚠️ ค่างวดที่ {$this->installment->installment_no} เลยกำหนดชำระแล้ว #{$this->booking->booking_ref}",
            'due_today' => "🔔 ถึงกำหนดชำระค่างวดที่ {$this->installment->installment_no} วันนี้ #{$this->booking->booking_ref}",
            default     => "📅 ใกล้ถึงกำหนดชำระค่างวดที่ {$this->installment->installment_no} #{$this->booking->booking_ref}",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.installment-due-reminder');
    }
}
