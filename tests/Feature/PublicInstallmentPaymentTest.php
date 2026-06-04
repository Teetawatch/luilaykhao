<?php

namespace Tests\Feature;

use App\Jobs\VerifySlipJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\InstallmentPayment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicInstallmentPaymentTest extends TestCase
{
    use RefreshDatabase;

    /** รอบผ่อน 3 งวด งวดละ 1000 — งวด 1 จ่ายแล้ว, งวด 2-3 รอชำระ */
    private function makeInstallmentBooking(): Booking
    {
        $user = User::factory()->create();

        $trip = Trip::create([
            'title' => 'Installment Trip', 'slug' => 'installment-trip', 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonths(4)->toDateString(),
            'return_date' => now()->addMonths(4)->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
            'installment_enabled' => true, 'installment_count' => 3, 'installment_interval_days' => 30,
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
            'booking_id' => $booking->id, 'title' => 'Mr.', 'name' => 'Passenger', 'phone' => '0812345678',
        ]);

        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 1, 'amount' => 1000, 'due_date' => now()->toDateString(), 'status' => 'paid', 'paid_at' => now()]);
        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 2, 'amount' => 1000, 'due_date' => now()->addDays(30)->toDateString(), 'status' => 'pending']);
        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 3, 'amount' => 1000, 'due_date' => now()->addDays(60)->toDateString(), 'status' => 'pending']);

        return $booking;
    }

    public function test_public_pay_page_loads_for_valid_token_without_login(): void
    {
        $booking = $this->makeInstallmentBooking();
        $token = $booking->ensurePaymentToken();

        $this->get('/pay/'.$token)
            ->assertOk()
            ->assertSee($booking->booking_ref)
            ->assertSee('งวดที่ 2')
            ->assertSee('data:image/svg+xml'); // ฝัง QR PromptPay
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->makeInstallmentBooking();

        $this->get('/pay/doesnotexist1')->assertNotFound();
    }

    public function test_public_submit_marks_next_installment_paid_and_dispatches_ocr(): void
    {
        Mail::fake();
        Queue::fake();
        Storage::fake('public');
        config()->set('services.thaibulksms.enabled', false);

        $booking = $this->makeInstallmentBooking();
        $token = $booking->ensurePaymentToken();

        $this->post('/pay/'.$token, [
            'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
            'payment_method' => 'promptpay',
            'transfer_datetime' => now()->format('Y-m-d\TH:i'),
        ])->assertRedirect('/pay/'.$token);

        $installment = InstallmentPayment::where('booking_id', $booking->id)
            ->where('installment_no', 2)->firstOrFail();

        $this->assertSame('paid', $installment->status);
        $this->assertNotNull($installment->slip_path);
        Storage::disk('public')->assertExists($installment->slip_path);
        $this->assertEquals(2000.0, (float) $booking->fresh()->paid_amount);

        Queue::assertPushed(VerifySlipJob::class);
    }

    public function test_submit_requires_slip_image(): void
    {
        $booking = $this->makeInstallmentBooking();
        $token = $booking->ensurePaymentToken();

        $this->from('/pay/'.$token)
            ->post('/pay/'.$token, ['payment_method' => 'promptpay'])
            ->assertRedirect('/pay/'.$token)
            ->assertSessionHasErrors('slip_image');
    }

    public function test_fully_paid_booking_shows_completion_page(): void
    {
        $booking = $this->makeInstallmentBooking();
        $booking->installmentPayments()->update(['status' => 'paid', 'paid_at' => now()]);
        $booking->update(['paid_amount' => 3000]);
        $token = $booking->ensurePaymentToken();

        $this->get('/pay/'.$token)
            ->assertOk()
            ->assertSee('ชำระครบทุกงวดแล้ว');
    }
}
