<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BookingSmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_friend_booking_skips_created_sms_and_sends_after_payment_confirmation(): void
    {
        Mail::fake();
        config()->set('services.thaibulksms.enabled', false);

        $user = User::factory()->create([
            'phone' => '0999999999',
        ]);

        $trip = Trip::create([
            'title' => 'Test Trip',
            'slug' => 'test-trip',
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: [[
                'title' => 'Ms.',
                'name' => 'Friend Passenger',
                'phone' => '081-234-5678',
                'email' => 'friend@example.test',
            ]],
            sendBookingCreatedSms: false,
        );

        $this->assertDatabaseMissing('sms_logs', [
            'booking_id' => $booking->id,
            'sms_type' => 'booking_created',
        ]);

        app(SmsService::class)->sendPaymentConfirmed($booking->fresh(['passengers', 'schedule.trip']), 'full');

        $this->assertDatabaseHas('sms_logs', [
            'booking_id' => $booking->id,
            'provider' => 'thaibulksms',
            'sms_type' => 'payment_confirmed',
            'dedupe_key' => 'full',
            'recipient' => '66812345678',
            'status' => 'pending',
        ]);
    }
}
