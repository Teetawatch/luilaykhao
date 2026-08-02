<?php

namespace App\Mail;

use App\Models\SosAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminSosAlertMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public SosAlert $alert,
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->alert->user?->name ?? 'ลูกค้า';
        $trip = $this->alert->schedule?->trip?->title ?? 'ทริป';

        return new Envelope(
            subject: '🆘 [ด่วน] SOS จาก '.$name.' — '.$trip,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-sos-alert',
        );
    }
}
