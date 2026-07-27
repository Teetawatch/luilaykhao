<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * bookings:sync-passenger-pickups — backfill ของเก่าที่หัวการจองเปลี่ยนจุดรับไปแล้ว
 * แต่แถวผู้โดยสารยังค้างจุดเดิม (ก่อนมีการซิงก์อัตโนมัติ 2026-07-27)
 */
class SyncPassengerPickupPointsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Test Trip',
            'slug' => 'test-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function makePickup(TripSchedule $schedule, string $location, int $sort): SchedulePickupPoint
    {
        return SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => $location,
            'price' => 1500,
            'sort_order' => $sort,
        ]);
    }

    private function makeBooking(TripSchedule $schedule, SchedulePickupPoint $point, int $seats = 2): Booking
    {
        return app(BookingService::class)->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $schedule->id,
            passengers: collect(range(1, $seats))->map(fn ($i) => [
                'title' => 'นาย',
                'name' => "ผู้เดินทาง {$i}",
                'phone' => '0812345678',
            ])->all(),
            pickupPointId: $point->id,
        );
    }

    public function test_it_moves_passengers_left_behind_at_the_old_point(): void
    {
        $schedule = $this->makeSchedule();
        $old = $this->makePickup($schedule, 'BTS หมอชิต', 1);
        $new = $this->makePickup($schedule, 'อนุสาวรีย์ชัยฯ', 2);

        $booking = $this->makeBooking($schedule, $old);
        // จำลองการแก้แบบเดิม: เปลี่ยนเฉพาะหัวการจอง
        $booking->update(['pickup_point_id' => $new->id]);

        $this->artisan('bookings:sync-passenger-pickups')->assertSuccessful();

        foreach ($booking->fresh()->passengers as $passenger) {
            $this->assertSame($new->id, (int) $passenger->pickup_point_id);
        }
    }

    public function test_dry_run_changes_nothing(): void
    {
        $schedule = $this->makeSchedule();
        $old = $this->makePickup($schedule, 'BTS หมอชิต', 1);
        $new = $this->makePickup($schedule, 'อนุสาวรีย์ชัยฯ', 2);

        $booking = $this->makeBooking($schedule, $old);
        $booking->update(['pickup_point_id' => $new->id]);

        $this->artisan('bookings:sync-passenger-pickups --dry-run')->assertSuccessful();

        foreach ($booking->fresh()->passengers as $passenger) {
            $this->assertSame($old->id, (int) $passenger->pickup_point_id);
        }
    }

    public function test_it_skips_bookings_where_passengers_chose_different_points(): void
    {
        $schedule = $this->makeSchedule();
        $a = $this->makePickup($schedule, 'BTS หมอชิต', 1);
        $b = $this->makePickup($schedule, 'อนุสาวรีย์ชัยฯ', 2);

        $booking = $this->makeBooking($schedule, $a);
        $second = $booking->passengers()->orderByDesc('id')->first();
        $second->update(['pickup_point_id' => $b->id]); // ลูกค้าเลือกจุดของตัวเอง

        $this->artisan('bookings:sync-passenger-pickups')->assertSuccessful();

        $this->assertSame($b->id, (int) $second->fresh()->pickup_point_id);

        // --all บังคับให้ทุกคนใช้จุดของหัวการจอง
        $this->artisan('bookings:sync-passenger-pickups --all')->assertSuccessful();
        $this->assertSame($a->id, (int) $second->fresh()->pickup_point_id);
    }

    public function test_it_clears_passenger_points_when_booking_uses_a_pinned_pickup(): void
    {
        $schedule = $this->makeSchedule();
        $point = $this->makePickup($schedule, 'BTS หมอชิต', 1);

        $booking = $this->makeBooking($schedule, $point);
        $booking->update([
            'pickup_point_id' => null,
            'pickup_region' => null,
            'custom_pickup_label' => 'ปั๊ม ปตท.',
            'custom_pickup_lat' => 14.4521,
            'custom_pickup_lng' => 101.3721,
            'custom_pickup_status' => Booking::CUSTOM_PICKUP_APPROVED,
        ]);

        $this->artisan('bookings:sync-passenger-pickups')->assertSuccessful();

        foreach ($booking->fresh()->passengers as $passenger) {
            $this->assertNull($passenger->pickup_point_id);
        }
    }

    public function test_it_leaves_cross_schedule_pickups_to_the_repair_command(): void
    {
        $schedule = $this->makeSchedule();
        $point = $this->makePickup($schedule, 'BTS หมอชิต', 1);
        $other = $this->makeSchedule();
        $foreign = $this->makePickup($other, 'ปากเกร็ด', 1);

        $booking = $this->makeBooking($schedule, $point);
        $booking->update(['pickup_point_id' => $foreign->id]);

        $this->artisan('bookings:sync-passenger-pickups')->assertSuccessful();

        foreach ($booking->fresh()->passengers as $passenger) {
            $this->assertSame($point->id, (int) $passenger->pickup_point_id);
        }
    }
}
