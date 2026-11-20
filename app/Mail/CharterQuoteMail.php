<?php

namespace App\Mail;

use App\Models\CharterRequest;
use App\Support\AppLocale;
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
            subject: __('ใบเสนอราคาเหมาทริป :ref - Luilaykhao', ['ref' => $this->charter->ref]),
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
                    ? AppLocale::fullDate($c->quote_departure_date)
                        .($c->quote_return_date && ! $c->quote_return_date->isSameDay($c->quote_departure_date)
                            ? ' – '.AppLocale::fullDate($c->quote_return_date)
                            : '')
                    : '-',
                'validUntilLabel' => $c->quote_valid_until ? AppLocale::fullDate($c->quote_valid_until) : null,
            ],
        );
    }
}
