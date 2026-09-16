<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * เช็คอินที่สตาฟกดตอนไม่มีสัญญาณ แล้วแอปส่งตามมาทีหลัง
 *
 * สองอย่างที่ต้องจริง: เวลาที่บันทึกคือตอนคนขึ้นรถ ไม่ใช่ตอนสัญญาณกลับมา และ
 * รายการที่ส่งซ้ำต้องได้คำตอบว่า "เรียบร้อยแล้ว" เพื่อให้คิวบนเครื่องปล่อยทิ้งได้
 */
class OfflineCheckInTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_check_in_keeps_the_time_it_actually_happened(): void
    {
        [$staff, $schedule, $booking] = $this->setUpAssignedStaff();

        $boardedAt = now()->subMinutes(90);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', [
                'qr_code' => $booking->qr_code,
                'checked_in_at' => $boardedAt->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.checked_in', true);

        $this->assertEqualsWithDelta(
            $boardedAt->timestamp,
            $booking->fresh()->checked_in_at->timestamp,
            5,
        );
    }

    public function test_replaying_a_queued_check_in_reports_success_instead_of_an_error(): void
    {
        [$staff, $schedule, $booking] = $this->setUpAssignedStaff();

        $payload = [
            'qr_code' => $booking->qr_code,
            'checked_in_at' => now()->subMinutes(30)->toIso8601String(),
        ];

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', $payload)
            ->assertOk();

        // คิวส่งซ้ำ (แอปเปิดใหม่ / สัญญาณติด ๆ ดับ ๆ) — ต้องไม่ขึ้นแดงค้างในมือสตาฟ
        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', $payload)
            ->assertOk()
            ->assertJsonPath('data.checked_in', true);
    }

    public function test_scanning_an_already_checked_in_code_by_hand_still_warns(): void
    {
        [$staff, $schedule, $booking] = $this->setUpAssignedStaff();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $booking->qr_code])
            ->assertOk();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $booking->qr_code])
            ->assertStatus(422);
    }

    public function test_a_wildly_wrong_device_clock_falls_back_to_now(): void
    {
        [$staff, $schedule, $booking] = $this->setUpAssignedStaff();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', [
                'qr_code' => $booking->qr_code,
                'checked_in_at' => now()->subYear()->toIso8601String(),
            ])
            ->assertOk();

        $this->assertEqualsWithDelta(
            now()->timestamp,
            $booking->fresh()->checked_in_at->timestamp,
            5,
        );
    }

    /** @return array{0: User, 1: TripSchedule, 2: Booking} */
    private function setUpAssignedStaff(): array
    {
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $customer = User::factory()->create();

        $trip = Trip::create([
            'title' => 'Offline Check-in Trip',
            'slug' => 'offline-check-in-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Nan',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->toDateString(),
            'return_date' => now()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);

        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Offline Passenger',
            'phone' => '0800000000',
        ]);

        return [$staff, $schedule, $booking];
    }
}
