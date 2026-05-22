<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\InstallmentPayment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentInstallmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * รอบผ่อน 3 งวด งวดละ 1000 — งวดที่ 1 จ่ายแล้ว, งวด 2-3 รอชำระ
     */
    private function makeInstallmentBooking(User $user): Booking
    {
        $trip = Trip::create([
            'title' => 'Installment Trip',
            'slug' => 'installment-trip',
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 3000,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonths(4)->toDateString(),
            'return_date' => now()->addMonths(4)->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
            'installment_enabled' => true,
            'installment_count' => 3,
            'installment_interval_days' => 30,
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3000,
            'paid_amount' => 1000,
            'payment_type' => 'installment',
            'installment_count' => 3,
            'installment_interval_days' => 30,
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'title' => 'Mr.',
            'name' => 'Passenger',
            'phone' => '0812345678',
        ]);

        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 1, 'amount' => 1000, 'due_date' => now()->toDateString(), 'status' => 'paid', 'paid_at' => now()]);
        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 2, 'amount' => 1000, 'due_date' => now()->addDays(30)->toDateString(), 'status' => 'pending']);
        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 3, 'amount' => 1000, 'due_date' => now()->addDays(60)->toDateString(), 'status' => 'pending']);

        return $booking;
    }

    public function test_customer_can_pay_an_installment_with_slip(): void
    {
        Mail::fake();
        Storage::fake('public');
        config()->set('services.thaibulksms.enabled', false);

        $user = User::factory()->create();
        $booking = $this->makeInstallmentBooking($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/payments/charge-installment', [
                'booking_ref' => $booking->booking_ref,
                'installment_no' => 2,
                'payment_method' => 'promptpay',
                'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
                'transfer_date' => now()->toDateString(),
                'transfer_time' => '10:30',
            ])
            ->assertOk()
            ->assertJsonPath('data.installment_no', 2);

        $installment = InstallmentPayment::where('booking_id', $booking->id)
            ->where('installment_no', 2)
            ->firstOrFail();

        $this->assertSame('paid', $installment->status);
        $this->assertNotNull($installment->slip_path);
        Storage::disk('public')->assertExists($installment->slip_path);

        // paid_amount เพิ่มจากงวดแรก 1000 เป็น 2000
        $this->assertEquals(2000.0, (float) $booking->fresh()->paid_amount);
    }

    public function test_cannot_pay_the_same_installment_twice(): void
    {
        Mail::fake();
        Storage::fake('public');
        config()->set('services.thaibulksms.enabled', false);

        $user = User::factory()->create();
        $booking = $this->makeInstallmentBooking($user);

        $payload = [
            'booking_ref' => $booking->booking_ref,
            'installment_no' => 2,
            'payment_method' => 'promptpay',
            'transfer_date' => now()->toDateString(),
            'transfer_time' => '10:30',
        ];

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/payments/charge-installment', $payload)
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/payments/charge-installment', $payload)
            ->assertStatus(422);

        // จ่ายซ้ำไม่ทำให้ยอดเกิน
        $this->assertEquals(2000.0, (float) $booking->fresh()->paid_amount);
    }

    public function test_installment_number_must_be_two_or_more(): void
    {
        $user = User::factory()->create();
        $booking = $this->makeInstallmentBooking($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/payments/charge-installment', [
                'booking_ref' => $booking->booking_ref,
                'installment_no' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('installment_no');
    }
}
