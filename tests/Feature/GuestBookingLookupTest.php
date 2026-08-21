<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingSeat;
use App\Models\ScheduleItineraryItem;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หน้า "ค้นหาการจอง" สำหรับคนที่ยังไม่ได้ล็อกอิน — คนที่ถือรหัสการจองต้องเห็น
 * รายละเอียดครบเหมือนเปิดในแอป ส่วนคนที่ค้นด้วยชื่อ+เบอร์ (ซึ่งใครก็รู้ได้) ต้อง
 * ไม่ได้รหัสการจอง QR ลิงก์แชร์ หรือตัวเลขเงินติดมือไปด้วย
 */
class GuestBookingLookupTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(): Booking
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง',
            'slug' => 'phu-kradueng-guest',
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'medium',
            'duration_days' => 3,
            'max_participants' => 20,
            'price_per_person' => 3500,
            'departure_point' => 'ปั๊ม ปตท. รังสิต',
            'status' => 'active',
        ]);

        $vehicle = Vehicle::create([
            'name' => 'รถตู้ 1',
            'type' => 'van',
            'capacity' => 13,
            'license_plate' => 'ฮก-1234',
            'driver_name' => 'พี่สมชาย',
            'driver_phone' => '0891112222',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDays(2)->toDateString(),
            'total_seats' => 13,
            'booked_seats' => 2,
            'transport_type' => 'van',
            'vehicle_id' => $vehicle->id,
            'status' => 'open',
        ]);

        $pickup = $schedule->pickupPoints()->create([
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'ปั๊ม ปตท. รังสิต',
            'pickup_time' => '19:30',
            'notes' => 'จอดฝั่งร้านกาแฟ',
            'price' => 0,
        ]);

        ScheduleItineraryItem::create([
            'schedule_id' => $schedule->id,
            'item_date' => $schedule->departure_date,
            'time' => '20:00',
            'title' => 'ออกเดินทางจากกรุงเทพฯ',
            'detail' => 'พร้อมกันก่อนเวลา 30 นาที',
        ]);

        $staff = User::factory()->create(['name' => 'พี่ไกด์', 'nickname' => 'ไกด์', 'phone' => '0812223333']);
        $schedule->staff()->attach($staff->id);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'pickup_point_id' => $pickup->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'payment_type' => 'deposit',
            'total_amount' => 7000,
            'paid_amount' => 3000,
            'deposit_amount' => 3000,
            'balance_amount' => 4000,
            'balance_due_at' => now()->addWeeks(2),
            'selected_addons' => [['name' => 'ถุงนอน', 'quantity' => 2, 'price' => 200]],
            'addons_total' => 400,
        ]);

        foreach ([['ต้น', '0810001111'], ['บอม', '0820002222']] as [$name, $phone]) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'title' => 'นาย',
                'name' => $name,
                'phone' => $phone,
                'pickup_point_id' => $pickup->id,
                'id_card' => '1234567890123',
                'allergies' => 'กุ้ง',
            ]);
        }

        BookingSeat::create([
            'booking_id' => $booking->id,
            'schedule_id' => $schedule->id,
            'seat_id' => 'A1',
            'passenger_name' => 'ต้น',
        ]);

        return $booking->fresh();
    }

    public function test_lookup_by_ref_returns_the_full_booking(): void
    {
        $booking = $this->makeBooking();

        $response = $this->postJson('/api/v1/bookings/guest-lookup', [
            'booking_ref' => $booking->booking_ref,
            'phone' => '1111',
        ])->assertOk();

        // คีย์เดิมที่หน้าติดตามรถอ่านอยู่ ต้องไม่หาย
        $response
            ->assertJsonPath('data.booking_ref', $booking->booking_ref)
            ->assertJsonPath('data.trip_title', 'ภูกระดึง')
            ->assertJsonPath('data.license_plate', 'ฮก-1234')
            ->assertJsonPath('data.schedule_id', $booking->schedule_id);

        // รายละเอียดที่เพิ่มเข้ามา
        $response
            ->assertJsonPath('data.trip.location', 'เลย')
            ->assertJsonPath('data.trip.duration_days', 3)
            ->assertJsonPath('data.schedule.return_date', $booking->schedule->return_date->toDateString())
            ->assertJsonPath('data.pickup.location', 'ปั๊ม ปตท. รังสิต')
            ->assertJsonPath('data.pickup.pickup_time', '19:30')
            ->assertJsonPath('data.pickup.notes', 'จอดฝั่งร้านกาแฟ')
            ->assertJsonPath('data.vehicle.driver_name', 'พี่สมชาย')
            ->assertJsonPath('data.staff.0.name', 'ไกด์')
            ->assertJsonPath('data.staff.0.phone', '0812223333')
            ->assertJsonPath('data.itinerary.0.title', 'ออกเดินทางจากกรุงเทพฯ')
            ->assertJsonCount(2, 'data.passengers')
            ->assertJsonPath('data.passengers.0.name', 'นาย ต้น')
            ->assertJsonPath('data.passengers.0.seat', 'A1')
            ->assertJsonPath('data.payment.payment_type', 'deposit')
            ->assertJsonPath('data.payment.total_amount', 7000)
            ->assertJsonPath('data.payment.outstanding_amount', 4000)
            ->assertJsonPath('data.payment.addons.0.name', 'ถุงนอน')
            ->assertJsonPath('data.payment.addons.0.quantity', 2);
    }

    public function test_passenger_phones_are_masked_and_encrypted_fields_never_leave(): void
    {
        $booking = $this->makeBooking();

        $response = $this->postJson('/api/v1/bookings/guest-lookup', [
            'booking_ref' => $booking->booking_ref,
            'phone' => '0810001111',
        ])->assertOk();

        $response->assertJsonPath('data.passengers.0.phone', '081-xxx-1111');

        $body = $response->getContent();
        $this->assertStringNotContainsString('1234567890123', $body);
        $this->assertStringNotContainsString('0810001111', $body);
        $this->assertStringNotContainsString('กุ้ง', $body);
    }

    public function test_lookup_by_name_hides_the_ref_qr_share_link_and_amounts(): void
    {
        $booking = $this->makeBooking();

        $response = $this->postJson('/api/v1/bookings/guest-lookup-by-name', [
            'name' => 'ต้น',
            'phone' => '0810001111',
        ])->assertOk();

        $response
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.booking_ref', null)
            ->assertJsonPath('data.0.qr_code', null)
            ->assertJsonPath('data.0.share_url', null)
            ->assertJsonPath('data.0.staff.0.phone', null)
            ->assertJsonPath('data.0.payment.amounts_hidden', true)
            ->assertJsonPath('data.0.payment.has_outstanding', true)
            ->assertJsonMissingPath('data.0.payment.total_amount');

        // แต่ยังบอกได้ว่าไปไหน เมื่อไหร่ ขึ้นรถที่ไหน
        $response
            ->assertJsonPath('data.0.trip_title', 'ภูกระดึง')
            ->assertJsonPath('data.0.pickup.location', 'ปั๊ม ปตท. รังสิต')
            ->assertJsonPath('data.0.itinerary.0.time', '20:00');

        $this->assertStringNotContainsString($booking->booking_ref, $response->getContent());
    }

    public function test_a_booking_is_listed_once_even_when_two_passengers_share_a_name(): void
    {
        $booking = $this->makeBooking();
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'title' => 'นาย',
            'name' => 'ต้น',
            'phone' => '0810001111',
        ]);

        $this->postJson('/api/v1/bookings/guest-lookup-by-name', [
            'name' => 'ต้น',
            'phone' => '0810001111',
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_wrong_phone_still_reveals_nothing(): void
    {
        $booking = $this->makeBooking();

        $this->postJson('/api/v1/bookings/guest-lookup', [
            'booking_ref' => $booking->booking_ref,
            'phone' => '9999',
        ])->assertStatus(403);

        $this->postJson('/api/v1/bookings/guest-lookup-by-name', [
            'name' => 'ต้น',
            'phone' => '0899999999',
        ])->assertStatus(404);
    }
}
