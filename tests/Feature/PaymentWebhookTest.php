<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function pendingBooking(): Booking
    {
        $user = User::factory()->create();

        $trip = Trip::create([
            'title' => 'Webhook Trip', 'slug' => 'webhook-trip', 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonths(2)->toDateString(),
            'return_date' => now()->addMonths(2)->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'pending',
            'total_amount' => 3000,
            'paid_amount' => 0,
            'payment_type' => 'full',
        ]);
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, config('payment.webhook_secret'));
    }

    public function test_webhook_is_disabled_without_a_configured_secret(): void
    {
        config(['payment.webhook_secret' => null]);

        $this->postJson('/api/v1/payments/webhook', ['event' => 'charge.complete'])
            ->assertStatus(503);
    }

    public function test_webhook_rejects_a_missing_signature(): void
    {
        config(['payment.webhook_secret' => 'shhh']);

        $this->postJson('/api/v1/payments/webhook', ['event' => 'charge.complete'])
            ->assertStatus(401);
    }

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        config(['payment.webhook_secret' => 'shhh']);

        $this->call(
            'POST',
            '/api/v1/payments/webhook',
            [],
            [],
            [],
            ['HTTP_X-Payment-Signature' => 'deadbeef', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['event' => 'charge.complete'])
        )->assertStatus(401);
    }

    public function test_valid_signature_confirms_a_pending_booking(): void
    {
        config(['payment.webhook_secret' => 'shhh']);
        $booking = $this->pendingBooking();

        $body = json_encode([
            'event' => 'charge.complete',
            'booking_ref' => $booking->booking_ref,
            'payment_ref' => 'CH_test_123',
        ]);

        $this->call(
            'POST',
            '/api/v1/payments/webhook',
            [],
            [],
            [],
            ['HTTP_X-Payment-Signature' => $this->sign($body), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $body
        )->assertOk();

        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('CH_test_123', $booking->payment_ref);
        $this->assertNotNull($booking->paid_at);
    }

    public function test_replaying_the_event_is_idempotent(): void
    {
        config(['payment.webhook_secret' => 'shhh']);
        $booking = $this->pendingBooking();

        $body = json_encode([
            'event' => 'charge.complete',
            'booking_ref' => $booking->booking_ref,
            'payment_ref' => 'CH_test_123',
        ]);
        $headers = ['HTTP_X-Payment-Signature' => $this->sign($body), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        $this->call('POST', '/api/v1/payments/webhook', [], [], [], $headers, $body)->assertOk();
        $firstPaidAt = $booking->fresh()->paid_at;

        // Replay must not re-confirm or overwrite the confirmed booking.
        $this->call('POST', '/api/v1/payments/webhook', [], [], [], $headers, $body)->assertOk();

        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('CH_test_123', $booking->payment_ref);
        $this->assertEquals($firstPaidAt->toISOString(), $booking->paid_at->toISOString());
    }
}
