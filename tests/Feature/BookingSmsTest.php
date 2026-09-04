<?php

namespace Tests\Feature;

use App\Models\Booking;
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

    public function test_customer_facing_sms_links_are_public_token_urls(): void
    {
        Mail::fake();
        config()->set('services.thaibulksms.enabled', false);

        [$booking, $sms] = $this->bookingWithSms();

        $sms->sendPaymentConfirmed($booking, 'full');
        $confirmed = $this->messageFor($booking, 'payment_confirmed');

        // ลิงก์ต้องเปิดได้โดยไม่ต้องล็อกอิน — SMS วิ่งเข้าเบอร์ผู้โดยสารคนแรก
        // ซึ่งอาจไม่มีบัญชีในระบบเลย
        $this->assertStringContainsString('/track/'.$booking->fresh()->share_token, $confirmed);
        $this->assertStringNotContainsString('/confirmation/', $confirmed);
        // และต้องบอกได้เองว่าไปทริปไหน วันไหน
        $this->assertStringContainsString('Test Trip', $confirmed);
        $this->assertStringContainsString('2569', $confirmed);

        $booking->forceFill([
            'deposit_amount' => 1500,
            'balance_amount' => 3500,
            'balance_due_at' => now()->addWeek(),
        ])->save();

        $sms->sendDepositPaid($booking->fresh());
        $deposit = $this->messageFor($booking, 'deposit_paid');

        $this->assertStringContainsString('/pay/'.$booking->fresh()->payment_token, $deposit);
        $this->assertStringNotContainsString('/installment-payment/', $deposit);
        // ยอดเต็มจำนวนไม่ต้องมี .00 ให้เปลืองเครดิต
        $this->assertStringContainsString('1,500 บาท', $deposit);
        $this->assertStringNotContainsString('1,500.00', $deposit);
    }

    public function test_cancellation_reason_is_clipped_so_the_message_stays_short(): void
    {
        Mail::fake();
        config()->set('services.thaibulksms.enabled', false);

        [$booking, $sms] = $this->bookingWithSms();

        // เหตุผลยกเลิกเป็นข้อความอิสระที่แอดมินพิมพ์เอง ยาวได้ไม่จำกัด
        $sms->sendBookingCancelled($booking, str_repeat('ก', 400));
        $message = $this->messageFor($booking, 'booking_cancelled');

        $this->assertLessThan(200, mb_strlen($message));
        $this->assertStringContainsString('ติดต่อทีมงาน', $message);
    }

    /**
     * @return array{0: Booking, 1: SmsService}
     */
    private function bookingWithSms(): array
    {
        $user = User::factory()->create(['phone' => '0999999999']);

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

        return [$booking->fresh(['passengers', 'schedule.trip']), app(SmsService::class)];
    }

    private function messageFor(Booking $booking, string $type): string
    {
        return (string) SmsLog::where('booking_id', $booking->id)
            ->where('sms_type', $type)
            ->value('message');
    }
}
