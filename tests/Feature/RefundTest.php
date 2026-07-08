<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ครอบคลุมนโยบายและ flow การคืนเงินฝั่งแอดมิน:
 * full/installment คืนตาม % ตามวันก่อนเดินทาง (7+ = 80%, 3–6 = 50%, <3 = 0%),
 * deposit มัดจำไม่คืน (คืนเฉพาะ balance ที่ชำระแล้ว), และ guard ต่าง ๆ ของ processRefund.
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeBooking(int $daysUntilDeparture, array $overrides = []): Booking
    {
        $trip = Trip::create([
            'title' => 'Refund Trip', 'slug' => 'refund-trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Pai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 5000, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays($daysUntilDeparture)->toDateString(),
            'return_date' => now()->addDays($daysUntilDeparture + 1)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 1,
            'transport_type' => 'van', 'status' => 'open',
        ]);

        return Booking::create(array_merge([
            'booking_ref' => 'LLK-REF-'.strtoupper(uniqid()),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'payment_type' => 'full',
            'total_amount' => 5000,
            'paid_amount' => 5000,
        ], $overrides));
    }

    public function test_preview_returns_80_percent_when_7_or_more_days_out(): void
    {
        $booking = $this->makeBooking(10);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund-preview")
            ->assertOk()
            ->assertJsonPath('data.refund_percent', 80)
            ->assertJsonPath('data.refund_amount', 4000);
    }

    public function test_preview_returns_50_percent_between_3_and_6_days_out(): void
    {
        $booking = $this->makeBooking(4);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund-preview")
            ->assertOk()
            ->assertJsonPath('data.refund_percent', 50)
            ->assertJsonPath('data.refund_amount', 2500);
    }

    public function test_preview_returns_zero_when_under_3_days_out(): void
    {
        $booking = $this->makeBooking(1);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund-preview")
            ->assertOk()
            ->assertJsonPath('data.refund_percent', 0)
            ->assertJsonPath('data.refund_amount', 0);
    }

    public function test_deposit_booking_never_refunds_the_deposit(): void
    {
        // มัดจำ 2000 จ่ายแล้ว, balance 3000 ยังไม่จ่าย → คืน 0
        $booking = $this->makeBooking(30, [
            'payment_type' => 'deposit',
            'total_amount' => 5000,
            'paid_amount' => 2000,
            'deposit_amount' => 2000,
            'balance_amount' => 3000,
            'balance_paid_at' => null,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund-preview")
            ->assertOk()
            ->assertJsonPath('data.refund_amount', 0);
    }

    public function test_deposit_booking_refunds_paid_balance_only(): void
    {
        // มัดจำ 2000 + balance 3000 จ่ายครบแล้ว → คืนเฉพาะ balance 3000
        $booking = $this->makeBooking(30, [
            'payment_type' => 'deposit',
            'total_amount' => 5000,
            'paid_amount' => 5000,
            'deposit_amount' => 2000,
            'balance_amount' => 3000,
            'balance_paid_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund-preview")
            ->assertOk()
            ->assertJsonPath('data.refund_amount', 3000);
    }

    public function test_processing_a_refund_marks_the_booking_and_frees_seats(): void
    {
        $booking = $this->makeBooking(10);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund", [
                'refund_amount' => 4000,
                'note' => 'ยกเลิกเพราะสภาพอากาศ',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.refund_amount', 4000);

        $booking->refresh();
        $this->assertSame('refunded', $booking->status);
        $this->assertSame('refunded', $booking->refund_status);
        $this->assertNotNull($booking->refunded_at);
        $this->assertSame(0, $booking->seats()->count());
    }

    public function test_admin_can_attach_a_transfer_slip_as_refund_proof(): void
    {
        Storage::fake(MediaDisk::slipDisk());
        $booking = $this->makeBooking(10);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund", [
                'refund_amount' => 4000,
                'refund_slip' => UploadedFile::fake()->image('refund.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded');

        $booking->refresh();
        $this->assertNotNull($booking->refund_slip_path);
        Storage::disk(MediaDisk::slipDisk())->assertExists($booking->refund_slip_path);
    }

    public function test_refund_amount_cannot_exceed_paid_amount(): void
    {
        $booking = $this->makeBooking(10);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund", [
                'refund_amount' => 9999,
            ])
            ->assertStatus(422);

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_an_already_refunded_booking_cannot_be_refunded_again(): void
    {
        $booking = $this->makeBooking(10, ['status' => 'refunded']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund", [
                'refund_amount' => 100,
            ])
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_process_a_refund(): void
    {
        $booking = $this->makeBooking(10);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund", [
                'refund_amount' => 100,
            ])
            ->assertForbidden();

        $this->assertSame('confirmed', $booking->fresh()->status);
    }
}
