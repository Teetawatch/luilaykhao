<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "กันกลาง": ยอดในสลิปไม่ตรง → ค้าง booking ไว้ (status ยัง pending) ให้แอดมินยืนยันก่อน
 * ยอดตรง → ยืนยันอัตโนมัติเหมือนเดิม
 */
class SlipAmountReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.thaibulksms.enabled', false);
        config()->set('services.anthropic.key', 'test-key');
        Storage::fake(MediaDisk::slipDisk());
    }

    /** Fake the Anthropic vision OCR response (service prefills assistant turn with "{"). */
    private function fakeOcr(array $json): void
    {
        $text = ltrim(json_encode($json, JSON_UNESCAPED_UNICODE), '{');
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => $text]],
            ], 200),
        ]);
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

    public function test_matching_amount_confirms_immediately(): void
    {
        $this->fakeOcr(['status' => 'success', 'amount' => 3000.0, 'datetime' => '2026-07-24 10:00:00']);

        $owner = User::factory()->create();
        $booking = $this->makePendingBooking($owner, 3000);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/payments/charge', [
                'booking_ref' => $booking->booking_ref,
                'payment_type' => 'full',
                'payment_method' => 'promptpay',
                'amount' => 3000,
                'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertEquals('confirmed', $booking->fresh()->status);
    }

    public function test_mismatching_amount_holds_for_review(): void
    {
        // OCR อ่านยอดได้ 500 แต่ต้องจ่าย 3000 → กันไว้รอแอดมิน
        $this->fakeOcr(['status' => 'success', 'amount' => 500.0, 'datetime' => '2026-07-24 10:00:00']);

        $owner = User::factory()->create();
        $booking = $this->makePendingBooking($owner, 3000);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/payments/charge', [
                'booking_ref' => $booking->booking_ref,
                'payment_type' => 'full',
                'payment_method' => 'promptpay',
                'amount' => 3000,
                'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_review');

        $booking->refresh();
        $this->assertEquals('pending', $booking->status, 'held booking must NOT be confirmed');
        $this->assertNull($booking->paid_at);
        $this->assertEquals('failed', $booking->slip_ocr_status);
        $this->assertNotNull($booking->slip_path);

        // แจ้งลูกค้าว่ากำลังตรวจสอบ (ไม่ใช่ "ยืนยันแล้ว")
        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $owner->id,
            'type' => 'payment_under_review',
        ]);
        $this->assertDatabaseMissing('smart_notifications', [
            'user_id' => $owner->id,
            'type' => 'payment_confirmed',
        ]);
    }

    public function test_held_booking_survives_ttl_expiry(): void
    {
        $this->fakeOcr(['status' => 'success', 'amount' => 500.0, 'datetime' => '2026-07-24 10:00:00']);

        $owner = User::factory()->create();
        $booking = $this->makePendingBooking($owner, 3000);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/payments/charge', [
                'booking_ref' => $booking->booking_ref,
                'payment_type' => 'full',
                'payment_method' => 'promptpay',
                'amount' => 3000,
                'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
            ])
            ->assertJsonPath('data.status', 'pending_review');

        // ทำให้ดูเหมือนสร้างมานานเกิน TTL แล้วรัน job ล้างของค้าง
        $booking->forceFill(['created_at' => now()->subMinutes(Booking::PENDING_TTL_MINUTES + 5)])->save();
        app(BookingService::class)->expireStalePendingBookings();

        $this->assertEquals('pending', $booking->fresh()->status, 'held booking must not be auto-cancelled');
    }

    public function test_admin_approval_confirms_held_booking(): void
    {
        $this->fakeOcr(['status' => 'success', 'amount' => 500.0, 'datetime' => '2026-07-24 10:00:00']);

        $owner = User::factory()->create();
        $booking = $this->makePendingBooking($owner, 3000);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/payments/charge', [
                'booking_ref' => $booking->booking_ref,
                'payment_type' => 'full',
                'payment_method' => 'promptpay',
                'amount' => 3000,
                'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
            ])
            ->assertJsonPath('data.status', 'pending_review');

        $this->assertEquals('pending', $booking->fresh()->status);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/slip/approve", [
                'slip_type' => 'main',
            ])
            ->assertOk();

        $booking->refresh();
        $this->assertEquals('confirmed', $booking->status);
        $this->assertEquals('manually_approved', $booking->slip_ocr_status);
        $this->assertNotNull($booking->paid_at);
        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $owner->id,
            'type' => 'payment_confirmed',
        ]);
    }
}
