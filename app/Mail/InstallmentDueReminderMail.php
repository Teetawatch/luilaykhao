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
        $params = ['no' => $this->installment->installment_no, 'ref' => $this->booking->booking_ref];
        $subject = match ($this->reminderType) {
            'overdue' => __('ค่างวดที่ :no เลยกำหนดชำระ #:ref', $params),
            'due_today' => __('ครบกำหนดชำระค่างวดที่ :no วันนี้ #:ref', $params),
            default => __('แจ้งเตือนกำหนดชำระค่างวดที่ :no #:ref', $params),
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.installment-due-reminder');
    }
}
