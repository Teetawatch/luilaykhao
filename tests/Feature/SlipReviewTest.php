<?php

namespace Tests\Feature;

use App\Jobs\NotifySlipReviewJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use App\Support\SlipStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * สลิปโอนเงิน (ทางสำรองเมื่อไม่ได้ใช้ Beam) — ไม่มี OCR อ่านให้แล้ว: ยืนยันที่นั่ง
 * ทันที แล้วแจ้งทีมงานให้ตรวจยอดตามหลัง ห้ามมีคำขอไปหา Claude
 */
class SlipReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.thaibulksms.enabled', false);
        config()->set('services.anthropic.key', 'test-key');
        Storage::fake(MediaDisk::slipDisk());
        Http::fake();
    }

    private function makePendingBooking(User $owner, float $total = 3000): Booking
    {
        $trip = Trip::create([
            'title' => 'Review Trip', 'slug' => 'review-trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 10, 'price_per_person' => $total, 'status' => 'active',
        ]);
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'pending',
            'total_amount' => $total,
        ]);
        BookingPassenger::create(['booking_id' => $booking->id, 'title' => 'Mr.', 'name' => 'Passenger']);

        return $booking;
    }

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function pay(User $owner, Booking $booking)
    {
        return $this->actingAs($owner, 'sanctum')->postJson('/api/v1/payments/charge', [
            'booking_ref' => $booking->booking_ref,
            'payment_type' => 'full',
            'payment_method' => 'promptpay',
            'amount' => 3000,
            'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
        ]);
    }

    public function test_a_slip_confirms_the_seat_and_waits_for_the_team_to_check(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makePendingBooking($owner, 3000);

        $this->pay($owner, $booking)->assertOk()->assertJsonPath('data.status', 'confirmed');

        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame(SlipStatus::PENDING, $booking->slip_ocr_status);
        $this->assertNotNull($booking->slip_path);
        Http::assertNothingSent();
    }

    public function test_the_team_is_told_a_slip_is_waiting(): void
    {
        Queue::fake([NotifySlipReviewJob::class]);
        $owner = User::factory()->create();
        $booking = $this->makePendingBooking($owner, 3000);

        $this->pay($owner, $booking)->assertOk();

        Queue::assertPushed(NotifySlipReviewJob::class, fn ($job) => $job->bookingRef === $booking->booking_ref);
    }

    public function test_admins_get_the_review_notice(): void
    {
        $admin = $this->makeAdmin();

        (new NotifySlipReviewJob('LLK-TEST-0001', 'LLK-TEST-0001'))->handle();

        $this->assertDatabaseHas('smart_notifications', ['user_id' => $admin->id, 'type' => 'slip_ocr_failed', 'title' => 'สลิปรอตรวจสอบ']);
    }

    public function test_an_old_held_booking_can_still_be_approved_by_the_team(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makePendingBooking($owner, 3000);
        // แถวเก่าที่ OCR เคยกันไว้ (ยอดไม่ตรง) ยังต้องอนุมัติได้
        $booking->forceFill([
            'payment_type' => 'full',
            'payment_method' => 'promptpay',
            'slip_path' => 'slips/old.jpg',
            'slip_ocr_status' => SlipStatus::FAILED,
        ])->save();

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/slip/approve", ['slip_type' => 'main'])
            ->assertOk();

        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame(SlipStatus::APPROVED, $booking->slip_ocr_status);
        $this->assertDatabaseHas('smart_notifications', ['user_id' => $owner->id, 'type' => 'payment_confirmed']);
    }
}
