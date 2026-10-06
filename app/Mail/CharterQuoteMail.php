<?php

namespace App\Mail;

use App\Models\CharterRequest;
use App\Support\ThaiDate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ใบเสนอราคาเหมาทริปถึงลูกค้า — ตอบรับ/ปฏิเสธทำในแอป
 */
class CharterQuoteMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public CharterRequest $charter,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ใบเสนอราคาเหมาทริป '.$this->charter->ref.' มาแล้วครับ 📝 - Luilaykhao',
        );
    }

    public function content(): Content
    {
        $c = $this->charter->loadMissing(['user', 'quoteTrip']);

        return new Content(
            view: 'emails.charter-quote',
            with: [
                'tripTitle' => $c->quoteTrip?->title ?? $c->destinationLabel(),
                'dateLabel' => $c->quote_departure_date
                    ? ThaiDate::full($c->quote_departure_date)
                        .($c->quote_return_date && ! $c->quote_return_date->isSameDay($c->quote_departure_date)
                            ? ' – '.ThaiDate::full($c->quote_return_date)
                            : '')
                    : '-',
                'validUntilLabel' => $c->quote_valid_until ? ThaiDate::full($c->quote_valid_until) : null,
            ],
        );
    }
}
