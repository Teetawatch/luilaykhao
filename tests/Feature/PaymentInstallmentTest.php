<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\InstallmentPayment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
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

    /**
     * แผนเก่าที่ทำไว้ตอนเพดานยังเป็น 6 งวด ต้องจ่ายต่อจนจบได้ ถึงแม้วันนี้จะสร้างแผน
     * แบบนั้นใหม่ไม่ได้แล้ว — ทุกเส้นทางการจ่ายอ่านจากแถวงวดจริง ไม่ได้เทียบกับเพดาน
     */
    public function test_a_six_instalment_plan_made_before_the_cap_can_still_be_paid_off(): void
    {
        Mail::fake();
        Storage::fake(MediaDisk::slipDisk());
        config()->set('services.thaibulksms.enabled', false);

        $user = User::factory()->create();
        $booking = $this->makeInstallmentBooking($user);

        // ขยายแผนเดิมเป็น 6 งวดแบบที่การจองเก่าบน production เป็นอยู่
        $booking->update(['installment_count' => 6, 'total_amount' => 6000]);
        foreach ([4, 5, 6] as $no) {
            InstallmentPayment::create([
                'booking_id' => $booking->id,
                'installment_no' => $no,
                'amount' => 1000,
                'due_date' => now()->addDays(30 * $no)->toDateString(),
                'status' => 'pending',
            ]);
        }

        foreach ([2, 3, 4, 5, 6] as $no) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/payments/charge-installment', [
                    'booking_ref' => $booking->booking_ref,
                    'installment_no' => $no,
                    'payment_method' => 'promptpay',
                    'slip_image' => UploadedFile::fake()->image("slip-{$no}.jpg", 400, 300),
                    'transfer_date' => now()->toDateString(),
                    'transfer_time' => '10:30',
                ])
                ->assertOk()
                ->assertJsonPath('data.installment_no', $no);
        }

        $booking->refresh()->load('installmentPayments');
        $this->assertSame(6, $booking->installmentPayments->where('status', 'paid')->count());
        $this->assertEquals(6000.0, (float) $booking->paid_amount);
    }

    public function test_customer_can_pay_an_installment_with_slip(): void
    {
        Mail::fake();
        Storage::fake(MediaDisk::slipDisk());
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
        Storage::disk(MediaDisk::slipDisk())->assertExists($installment->slip_path);

        // paid_amount เพิ่มจากงวดแรก 1000 เป็น 2000
        $this->assertEquals(2000.0, (float) $booking->fresh()->paid_amount);
    }

    public function test_cannot_pay_the_same_installment_twice(): void
    {
        Mail::fake();
        Storage::fake(MediaDisk::slipDisk());
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
