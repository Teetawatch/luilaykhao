<?php

namespace Tests\Feature;

use App\Jobs\ProcessWaitlistJob;
use App\Jobs\SendBroadcastNotificationJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BroadcastDispatch;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripAlert;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ลบ/ยกเลิกการจองในรอบที่ที่นั่งตึงอยู่ = ที่นั่งว่างคืนมา ต้องประกาศให้ลูกค้ารู้
 * (เดิมมีแต่แจ้งเตือนขาขึ้น "ใกล้เต็ม/เต็มแล้ว" ขาลงเงียบสนิท)
 */
class SeatFreedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'operator', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeTrip(): Trip
    {
        return Trip::create([
            'title' => 'Doi Luang',
            'slug' => 'doi-luang-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Rai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip, int $totalSeats): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => $totalSeats,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function makeBooking(TripSchedule $schedule, int $passengerCount): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 2500 * $passengerCount,
        ]);

        for ($i = 0; $i < $passengerCount; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'name' => 'Traveller '.$i,
                'phone' => '081000000'.$i,
            ]);
        }

        $schedule->syncBookedSeats();

        return $booking;
    }

    public function test_deleting_a_booking_on_a_full_round_announces_the_freed_seat(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 4);
        $this->makeBooking($schedule, 3);
        $booking = $this->makeBooking($schedule, 1); // รอบเต็มพอดี

        $watcher = User::factory()->create();
        TripAlert::create([
            'user_id' => $watcher->id,
            'trip_id' => $trip->id,
            'low_seat_threshold' => 3,
        ]);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->deleteJson("/api/v1/admin/bookings/{$booking->booking_ref}")
            ->assertOk();

        $this->assertSame(1, $schedule->fresh()->available_seats);

        // ประกาศถึงลูกค้าทุกคน
        $this->assertDatabaseHas('broadcast_dispatches', [
            'event_type' => 'seats_freed',
            'dedupe_key' => "seats_freed:{$schedule->id}:1",
        ]);
        Queue::assertPushed(
            fn (SendBroadcastNotificationJob $job) => $job->type === 'seats_freed'
                && $job->data['schedule_id'] === $schedule->id,
        );

        // คนที่ติดตามทริปนี้ได้แจ้งเตือนด้วย
        $note = SmartNotification::where('user_id', $watcher->id)
            ->where('type', 'trip_alert')
            ->first();
        $this->assertNotNull($note);
        $this->assertSame('seats_freed', $note->data['alert_type']);

        // และคิวรอถูกประมวลผลใหม่เสมอ
        Queue::assertPushed(ProcessWaitlistJob::class);
    }

    public function test_deleting_a_booking_on_a_wide_open_round_stays_quiet(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 20);
        $booking = $this->makeBooking($schedule, 2); // ว่างอีก 18 ที่ — ไม่ใช่ข่าว

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->deleteJson("/api/v1/admin/bookings/{$booking->booking_ref}")
            ->assertOk();

        $this->assertSame(0, BroadcastDispatch::where('event_type', 'seats_freed')->count());
    }

    public function test_repeated_deletes_at_the_same_seat_level_only_announce_once(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 4);
        $this->makeBooking($schedule, 2);
        $first = $this->makeBooking($schedule, 1);
        $second = $this->makeBooking($schedule, 1); // เต็มพอดี

        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/bookings/{$first->booking_ref}")
            ->assertOk();
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/bookings/{$second->booking_ref}")
            ->assertOk();

        // เหลือ 1 แล้ว 2 ที่ — คนละระดับ จึงประกาศระดับละครั้ง ไม่ซ้ำระดับเดิม
        $this->assertSame(2, BroadcastDispatch::where('event_type', 'seats_freed')->count());
        $this->assertDatabaseHas('broadcast_dispatches', ['dedupe_key' => "seats_freed:{$schedule->id}:1"]);
        $this->assertDatabaseHas('broadcast_dispatches', ['dedupe_key' => "seats_freed:{$schedule->id}:2"]);
    }

    public function test_a_waiting_queue_gets_the_seat_before_the_public_does(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 2);
        $this->makeBooking($schedule, 1);
        $booking = $this->makeBooking($schedule, 1);

        WaitlistEntry::create([
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'seat_count' => 1,
            'status' => 'waiting',
        ]);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->deleteJson("/api/v1/admin/bookings/{$booking->booking_ref}")
            ->assertOk();

        // คิวรอมีสิทธิ์ก่อน — ยังไม่ประกาศให้คนทั่วไปแย่งที่
        $this->assertSame(0, BroadcastDispatch::where('event_type', 'seats_freed')->count());
        Queue::assertPushed(ProcessWaitlistJob::class);
    }

    public function test_cancelling_a_booking_also_announces_the_freed_seat(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 4);
        $this->makeBooking($schedule, 3);
        $booking = $this->makeBooking($schedule, 1); // เต็มพอดี

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->putJson("/api/v1/admin/bookings/{$booking->booking_ref}/status", [
                'status' => 'cancelled',
                'cancellation_reason' => 'ลูกค้าติดธุระ',
            ])
            ->assertOk();

        $this->assertDatabaseHas('broadcast_dispatches', [
            'event_type' => 'seats_freed',
            'dedupe_key' => "seats_freed:{$schedule->id}:1",
        ]);
    }
}
