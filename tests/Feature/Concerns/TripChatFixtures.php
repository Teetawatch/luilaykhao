<?php

namespace Tests\Feature\Concerns;

use App\Jobs\SendChatPushJob;
use App\Jobs\SettleChatPollsJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;

/**
 * ห้องแชททริปที่กำลังเดินทาง + ใบจอง + ทีมงาน + push ที่ถูกส่ง — ใช้ร่วมในเทสเครื่องมือหน้างาน
 */
trait TripChatFixtures
{
    /** @var array<int, array{user: int, title: string, body: string, data: array<string, string>}> */
    protected array $pushes = [];

    protected function fakeTripChatPushes(): void
    {
        Bus::fake([SendChatPushJob::class]);

        $this->pushes = [];
        $this->mock(FcmService::class, function (MockInterface $m) {
            $m->shouldReceive('sendToUser')->andReturnUsing(function ($userId, $title, $body, $data = []) {
                $this->pushes[] = ['user' => (int) $userId, 'title' => $title, 'body' => $body, 'data' => $data];
            });
        });
    }

    protected function tripSchedule(array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปภูกระดึง',
            'slug' => 'tool-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1900,
            'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    /**
     * ใบจองหนึ่งใบ เจ้าของจองให้ทั้งกลุ่ม
     *
     * @param  array<int, string>  $names
     * @return array{0: User, 1: Booking, 2: array<int, BookingPassenger>}
     */
    protected function party(TripSchedule $schedule, array $names, array $bookingOverrides = []): array
    {
        $owner = User::factory()->create(['phone' => '0899999999']);
        $booking = Booking::create(array_merge([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1900 * count($names),
        ], $bookingOverrides));

        $passengers = array_map(fn ($n) => BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => $n,
            'phone' => '0811111111',
            'pickup_point_id' => $bookingOverrides['pickup_point_id'] ?? null,
        ]), $names);

        return [$owner, $booking, $passengers];
    }

    protected function staffOf(TripSchedule $schedule, string $nickname = 'พี่สตาฟ'): User
    {
        Role::findOrCreate('staff');
        $staff = User::factory()->create(['nickname' => $nickname]);
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return $staff;
    }

    protected function settleChat(): void
    {
        app()->call([new SettleChatPollsJob, 'handle']);
    }

    /** @return array<int, array{user: int, title: string, body: string, data: array<string, string>}> */
    protected function pushesTo(User $user): array
    {
        return array_values(array_filter($this->pushes, fn ($p) => $p['user'] === $user->id));
    }

    protected function chatUrl(TripSchedule $schedule, string $path): string
    {
        return "/api/v1/schedules/{$schedule->id}/chat/{$path}";
    }
}
