<?php

namespace Tests\Feature;

use App\Models\SmsLog;
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

    public function test_booking_creation_skips_sms_and_payment_confirmation_sends_simple_message(): void
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

        // ตัวข้อความปรับถ้อยคำได้เรื่อย ๆ — ที่ต้องคงไว้คือลูกค้าต้องเห็นเลขที่จอง
        $this->assertStringContainsString(
            $booking->booking_ref,
            SmsLog::where('booking_id', $booking->id)->where('sms_type', 'payment_confirmed')->value('message'),
        );
    }
}
