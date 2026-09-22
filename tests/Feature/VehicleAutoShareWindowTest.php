<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "มือถือของสตาฟควรเริ่มส่งเองเมื่อไหร่ และส่งถี่แค่ไหน"
 *
 * คนละคำถามกับ "เซิร์ฟเวอร์ยอมรับพิกัดไหม" ซึ่งเปิดกว้างกว่ามาก — เปิดเองตั้งแต่
 * เย็นวันก่อนแปลว่าออกอากาศตำแหน่งส่วนตัวของสตาฟทั้งคืนในนามของรถ
 */
class VehicleAutoShareWindowTest extends TestCase
{
    use RefreshDatabase;

    private function mode(TripSchedule $schedule, string $atThaiTime): ?string
    {
        $now = Carbon::parse(
            now('Asia/Bangkok')->toDateString().' '.$atThaiTime,
        );

        return app(VehicleLocationService::class)->autoShareMode($schedule->fresh(), $now);
    }

    public function test_it_does_not_start_the_night_before(): void
    {
        $schedule = $this->round(departsAt: '06:00');

        // สามทุ่มของคืนก่อนยังอยู่ในกรอบที่เซิร์ฟเวอร์ยอมรับพิกัด แต่ต้องไม่เปิดเอง
        $this->assertTrue(
            app(VehicleLocationService::class)->withinSharingWindow(
                $schedule,
                Carbon::parse(now('Asia/Bangkok')->subDay()->toDateString().' 21:00'),
            ),
        );
        $this->assertNull($this->mode($schedule, '02:00'));
    }

    public function test_it_starts_about_an_hour_and_a_half_before_the_van_leaves(): void
    {
        $schedule = $this->round(departsAt: '06:00');

        $this->assertNull($this->mode($schedule, '04:00'));
        $this->assertSame('pickup', $this->mode($schedule, '04:45'));
        $this->assertSame('pickup', $this->mode($schedule, '07:30'));
    }

    public function test_it_drops_to_the_saving_mode_once_every_stop_is_done(): void
    {
        $schedule = $this->round(departsAt: '06:00');
        $a = $this->point($schedule, 'จุด A', 1);
        $b = $this->point($schedule, 'จุด B', 2);

        $this->assertSame('pickup', $this->mode($schedule, '07:00'));

        $a->update(['completed_at' => now()]);
        $this->assertSame('pickup', $this->mode($schedule, '07:00'), 'ยังเหลือจุด B');

        $b->update(['completed_at' => now()]);
        $this->assertSame('onboard', $this->mode($schedule, '07:00'));
    }

    public function test_a_round_without_pickup_points_uses_check_ins_instead(): void
    {
        $schedule = $this->round(departsAt: '06:00');
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 1000,
        ]);

        $this->assertSame('pickup', $this->mode($schedule, '07:00'));

        $booking->update(['checked_in' => true, 'checked_in_at' => now()]);
        $this->assertSame('onboard', $this->mode($schedule, '07:00'));
    }

    public function test_it_stops_itself_after_the_round_is_over(): void
    {
        $schedule = $this->round(departsAt: '06:00');
        $schedule->update([
            'departure_date' => now('Asia/Bangkok')->subDays(3)->toDateString(),
            'return_date' => now('Asia/Bangkok')->subDays(2)->toDateString(),
            'departs_at' => now('Asia/Bangkok')->subDays(3)->format('Y-m-d').' 06:00:00',
        ]);

        $this->assertNull($this->mode($schedule, '07:00'));
    }

    public function test_a_cancelled_round_never_shares(): void
    {
        $schedule = $this->round(departsAt: '06:00');
        $schedule->update(['status' => 'cancelled']);

        $this->assertNull($this->mode($schedule, '07:00'));
    }

    private function point(TripSchedule $schedule, string $label, int $order): SchedulePickupPoint
    {
        return SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => $label,
            'price' => 0,
            'sort_order' => $order,
        ]);
    }

    private function round(string $departsAt): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ยอดดอยหลวง',
            'slug' => 'autoshare-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงราย',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);

        $vehicle = Vehicle::create([
            'name' => 'รถตู้คันที่ 1',
            'type' => 'van',
            'capacity' => 10,
            'license_plate' => 'ฮก 8899',
        ]);

        $today = now('Asia/Bangkok')->toDateString();

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'departure_date' => $today,
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'departs_at' => "{$today} {$departsAt}:00",
            'total_seats' => 12,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }
}
