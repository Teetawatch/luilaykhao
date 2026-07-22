<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Heads-up sent a few days before departure when a round still has fewer than
 * the guaranteed minimum number of booked seats. Framed as a warm courtesy
 * note about how the round gets confirmed — not an alarm — and no payment
 * action is required.
 */
class TripUnderfilledWarningMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public int $daysBefore,
        public int $bookedSeats,
        public int $minSeats,
    ) {}

    public function envelope(): Envelope
    {
        $title = $this->booking->schedule->trip->title ?? 'ทริป';

        return new Envelope(
            subject: "ข้อมูลเพิ่มเติมสำหรับการเดินทางและการยืนยันรอบทริป {$title} 🌿 #{$this->booking->booking_ref}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.trip-underfilled-warning',
            with: [
                'daysBefore' => $this->daysBefore,
                'bookedSeats' => $this->bookedSeats,
                'minSeats' => $this->minSeats,
            ],
        );
    }
}
