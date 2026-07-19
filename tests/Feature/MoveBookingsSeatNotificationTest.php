<?php

namespace Tests\Feature;

use App\Jobs\SendBroadcastNotificationJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BroadcastDispatch;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripAlert;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ย้ายผู้โดยสารเข้ารอบจนเต็มต้องแจ้งเตือนเหมือนตอนลูกค้าจองเอง
 * (เดิมแจ้งเตือนยิงจาก BookingService::createBooking ทางเดียว)
 */
class MoveBookingsSeatNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        // แจ้งเตือนแอดมินยิงถึง role admin+operator — ต้องมีครบ ไม่งั้น query โยน RoleDoesNotExist
        Role::firstOrCreate(['name' => 'operator', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeTrip(string $title): Trip
    {
        return Trip::create([
            'title' => $title,
            'slug' => str()->slug($title).'-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
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
            'total_amount' => 1500 * $passengerCount,
        ]);

        for ($i = 0; $i < $passengerCount; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'name' => 'Mover '.$i,
                'phone' => '080000000'.$i,
            ]);
        }

        $schedule->syncBookedSeats();

        return $booking;
    }

    public function test_move_that_fills_the_target_round_blasts_sold_out(): void
    {
        Mail::fake();
        Queue::fake();

        $admin = $this->makeAdmin();
        $sourceTrip = $this->makeTrip('Source Trip');
        $targetTrip = $this->makeTrip('Other Trip');
        $source = $this->makeSchedule($sourceTrip, 10);
        $target = $this->makeSchedule($targetTrip, 2); // ย้าย 2 คนเข้าไปแล้วเต็มพอดี

        $watcher = User::factory()->create();
        TripAlert::create([
            'user_id' => $watcher->id,
            'trip_id' => $targetTrip->id,
            'low_seat_threshold' => 3,
        ]);

        $booking = $this->makeBooking($source, 2);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules/move-bookings', [
                'source_schedule_id' => $source->id,
                'target_schedule_id' => $target->id,
                'passenger_ids' => $booking->passengers->pluck('id')->all(),
            ])
            ->assertOk();

        $this->assertSame(0, $target->fresh()->available_seats);

        // broadcast ถึงลูกค้าทุกคน (ชวนเข้าคิว waitlist)
        $this->assertDatabaseHas('broadcast_dispatches', [
            'event_type' => 'sold_out',
            'dedupe_key' => "sold_out:{$target->id}",
        ]);
        Queue::assertPushed(
            fn (SendBroadcastNotificationJob $job) => $job->type === 'sold_out'
                && $job->data['schedule_id'] === $target->id,
        );

        // คนที่ติดตามทริปปลายทางได้แจ้งเตือนด้วย
        $watcherNote = SmartNotification::where('user_id', $watcher->id)
            ->where('type', 'trip_alert')
            ->first();
        $this->assertNotNull($watcherNote);
        $this->assertSame('sold_out', $watcherNote->data['alert_type']);

        // แอดมินได้แจ้งเตือน "รอบเดินทางเต็มแล้ว"
        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $admin->id,
            'type' => 'schedule_full',
        ]);
    }

    public function test_move_into_the_low_band_blasts_low_seats(): void
    {
        Mail::fake();
        Queue::fake();

        $admin = $this->makeAdmin();
        $sourceTrip = $this->makeTrip('Source Trip');
        $targetTrip = $this->makeTrip('Other Trip');
        $source = $this->makeSchedule($sourceTrip, 10);
        $target = $this->makeSchedule($targetTrip, 4); // ย้าย 2 คน → เหลือ 2 ที่นั่ง

        $booking = $this->makeBooking($source, 2);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules/move-bookings', [
                'source_schedule_id' => $source->id,
                'target_schedule_id' => $target->id,
                'passenger_ids' => $booking->passengers->pluck('id')->all(),
            ])
            ->assertOk();

        $this->assertDatabaseHas('broadcast_dispatches', [
            'event_type' => 'low_seats',
            'dedupe_key' => "low_seats:{$target->id}:2",
        ]);
    }

    public function test_moving_passengers_away_does_not_blast_anything(): void
    {
        Mail::fake();
        Queue::fake();

        $admin = $this->makeAdmin();
        $sourceTrip = $this->makeTrip('Source Trip');
        $targetTrip = $this->makeTrip('Other Trip');
        $source = $this->makeSchedule($sourceTrip, 2);
        $target = $this->makeSchedule($targetTrip, 20);

        $booking = $this->makeBooking($source, 2);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules/move-bookings', [
                'source_schedule_id' => $source->id,
                'target_schedule_id' => $target->id,
                'passenger_ids' => $booking->passengers->pluck('id')->all(),
            ])
            ->assertOk();

        // รอบต้นทางว่างลง ไม่ใช่เหตุการณ์ที่ต้องแจ้งเตือนกลุ่มนี้
        $this->assertSame(0, BroadcastDispatch::where('event_type', 'sold_out')->count());
        $this->assertSame(0, BroadcastDispatch::where('event_type', 'low_seats')->count());
    }
}
