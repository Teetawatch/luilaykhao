<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use App\Services\SeatLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BookingSeatCollisionTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(): Trip
    {
        return Trip::create([
            'title' => 'Test Trip',
            'slug' => 'test-trip',
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function passengersFor(array $seatIds): array
    {
        return collect($seatIds)->map(fn ($s, $i) => [
            'title' => 'Mr.',
            'name' => "Passenger {$i}",
            'phone' => '0812345678',
            'email' => "p{$i}@example.test",
        ])->all();
    }

    /**
     * จำลองเคสจริง: Redis lock หายไป (TTL หมด / ล่ม / ถูก forceUnlock หลัง booking แรกสำเร็จ)
     * แต่แถวใน booking_seats ยังอยู่ — การจองที่สองต้องโดนปฏิเสธด้วยข้อความไทย
     * ไม่ใช่หลุดไปชน unique constraint จนกลายเป็น SQL error 500
     */
    public function test_booking_a_seat_already_in_booking_seats_is_rejected_cleanly(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule($this->makeTrip());
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $service = app(BookingService::class);

        // User A จอง A2 สำเร็จ (Redis ใน test ใช้ไม่ได้ → isLockedByUser คืน true ตาม fallback)
        $first = $service->createBooking(
            userId: $userA->id,
            scheduleId: $schedule->id,
            passengers: $this->passengersFor(['A2']),
            seatIds: ['A2'],
        );

        $this->assertDatabaseHas('booking_seats', [
            'schedule_id' => $schedule->id,
            'seat_id' => 'A2',
            'booking_id' => $first->id,
        ]);

        // User B พยายามจอง A2 ที่ถูกจองไปแล้ว — ต้องได้ข้อความไทย ไม่ใช่ QueryException
        try {
            $service->createBooking(
                userId: $userB->id,
                scheduleId: $schedule->id,
                passengers: $this->passengersFor(['A2']),
                seatIds: ['A2'],
            );
            $this->fail('คาดว่าจะ throw exception เพราะที่นั่ง A2 ถูกจองไปแล้ว');
        } catch (\Exception $e) {
            $this->assertStringContainsString('ถูกจองไปแล้ว', $e->getMessage());
            $this->assertStringContainsString('A2', $e->getMessage());
        }

        // ที่นั่ง A2 ต้องมีแถวเดียว และยังเป็นของ User A
        $this->assertSame(1, BookingSeat::where('schedule_id', $schedule->id)
            ->where('seat_id', 'A2')->count());
        $this->assertSame($first->id, BookingSeat::where('schedule_id', $schedule->id)
            ->where('seat_id', 'A2')->value('booking_id'));
    }

    /**
     * ที่นั่งของการจองที่ถูกยกเลิกแล้ว ต้องจองใหม่ได้ (cancelBooking ลบแถว booking_seats ทิ้ง)
     */
    public function test_cancelled_booking_frees_the_seat_for_rebooking(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule($this->makeTrip());
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $service = app(BookingService::class);

        $first = $service->createBooking(
            userId: $userA->id,
            scheduleId: $schedule->id,
            passengers: $this->passengersFor(['A2']),
            seatIds: ['A2'],
        );

        $service->cancelBooking($first->fresh(['seats', 'schedule.trip']), 'ลูกค้ายกเลิก');

        // ที่นั่งว่างแล้ว — User B จองได้
        $second = $service->createBooking(
            userId: $userB->id,
            scheduleId: $schedule->id,
            passengers: $this->passengersFor(['A2']),
            seatIds: ['A2'],
        );

        $this->assertSame(1, BookingSeat::where('schedule_id', $schedule->id)
            ->where('seat_id', 'A2')->count());
        $this->assertSame($second->id, BookingSeat::where('schedule_id', $schedule->id)
            ->where('seat_id', 'A2')->value('booking_id'));
    }

    /**
     * ตอน Redis ใช้ไม่ได้ (fallback path ใน test env) lock() ต้องไม่แจกล็อกให้ที่นั่งที่ถูกจองจริงไปแล้ว
     * — กันไม่ให้ผู้ใช้เลือกที่นั่งที่เต็มแล้วไปจนถึงหน้าชำระเงินโดยเปล่าประโยชน์
     */
    public function test_lock_is_refused_for_an_already_booked_seat_when_redis_is_down(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule($this->makeTrip());
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $service = app(BookingService::class);
        $seatLock = app(SeatLockService::class);

        // ที่นั่งว่าง — ล็อกได้
        $this->assertTrue($seatLock->lock($schedule->id, 'A2', $userA->id)['locked']);

        // User A จอง A2 สำเร็จ
        $service->createBooking(
            userId: $userA->id,
            scheduleId: $schedule->id,
            passengers: $this->passengersFor(['A2']),
            seatIds: ['A2'],
        );

        // ตอนนี้ A2 ถูกจองจริงแล้ว — User B ขอล็อกต้องโดนปฏิเสธพร้อมข้อความไทย
        $result = $seatLock->lock($schedule->id, 'A2', $userB->id);
        $this->assertFalse($result['locked']);
        $this->assertStringContainsString('ถูกจองไปแล้ว', $result['message']);

        // ที่นั่งว่างอื่นยังล็อกได้ตามปกติ
        $this->assertTrue($seatLock->lock($schedule->id, 'A3', $userB->id)['locked']);
    }
}
