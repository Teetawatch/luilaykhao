<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeRegistrationMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ยินดีต้อนรับสู่ครอบครัวลุยเลเขา 🌿',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome-registration',
        );
    }
}
