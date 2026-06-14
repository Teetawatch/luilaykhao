<?php

namespace Tests\Feature;

use App\Jobs\VerifySlipJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicBalancePaymentTest extends TestCase
{
    use RefreshDatabase;

    /** มัดจำ 1000 จาก 3000 — เหลือ balance 2000 ที่ยังไม่จ่าย */
    private function makeDepositBooking(): Booking
    {
        $user = User::factory()->create();

        $trip = Trip::create([
            'title' => 'Deposit Trip', 'slug' => 'deposit-trip', 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonths(2)->toDateString(),
            'return_date' => now()->addMonths(2)->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
            'deposit_enabled' => true, 'deposit_type' => 'amount', 'deposit_amount' => 1000,
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3000,
            'paid_amount' => 1000,
            'payment_type' => 'deposit',
            'deposit_amount' => 1000,
            'balance_amount' => 2000,
            'balance_due_at' => now()->addMonth()->toDateString(),
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id, 'title' => 'Mr.', 'name' => 'Passenger',
            'phone' => '0812345678', 'email' => 'pax@example.test',
        ]);

        return $booking;
    }

    public function test_balance_pay_page_loads_with_qr_and_amount(): void
    {
        $booking = $this->makeDepositBooking();
        $token = $booking->ensurePaymentToken();

        $this->get('/pay/'.$token)
            ->assertOk()
            ->assertSee($booking->booking_ref)
            ->assertSee('ยอดส่วนที่เหลือ')
            ->assertSee('data:image/svg+xml');
    }

    public function test_public_submit_settles_balance_and_dispatches_ocr(): void
    {
        Mail::fake();
        Queue::fake();
        Storage::fake(MediaDisk::slipDisk());
        config()->set('services.thaibulksms.enabled', false);

        $booking = $this->makeDepositBooking();
        $token = $booking->ensurePaymentToken();

        $this->post('/pay/'.$token, [
            'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
            'payment_method' => 'promptpay',
            'transfer_datetime' => now()->format('Y-m-d\TH:i'),
        ])->assertRedirect('/pay/'.$token);

        $booking->refresh();
        $this->assertNotNull($booking->balance_paid_at);
        $this->assertEquals(3000.0, (float) $booking->paid_amount);
        $this->assertNotNull($booking->balance_slip_path);
        Storage::disk(MediaDisk::slipDisk())->assertExists($booking->balance_slip_path);

        Queue::assertPushed(VerifySlipJob::class);

        // หลังจ่ายครบ หน้าแสดงสถานะชำระครบ
        $this->get('/pay/'.$token)->assertOk()->assertSee('ชำระครบแล้ว');
    }
}
