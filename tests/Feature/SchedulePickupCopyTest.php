<?php

namespace Tests\Feature;

use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SchedulePickupCopyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->trip = Trip::create([
            'title' => 'ทริปเขาใหญ่', 'slug' => 'khao-yai', 'type' => 'trekking', 'location' => 'X',
            'difficulty' => 'easy', 'duration_days' => 1, 'max_participants' => 20,
            'price_per_person' => 1500, 'status' => 'active',
        ]);
    }

    private function makeSchedule(string $date): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $this->trip->id, 'departure_date' => $date, 'return_date' => $date,
            'total_seats' => 12, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    private function addPoint(TripSchedule $s, array $attrs = []): SchedulePickupPoint
    {
        return SchedulePickupPoint::create(array_merge([
            'schedule_id' => $s->id, 'region' => 'central', 'region_label' => 'ภาคกลาง',
            'pickup_location' => 'ปั๊ม ปตท. รังสิต', 'price' => 1650, 'pickup_time' => '05:30',
            'sort_order' => 0,
        ], $attrs));
    }

    private function createSchedulePayload(string $date): array
    {
        return [
            'trip_id' => $this->trip->id, 'departure_date' => $date, 'return_date' => $date,
            'total_seats' => 12, 'transport_type' => 'van',
        ];
    }

    public function test_new_schedule_auto_copies_pickup_points_from_latest_previous_round(): void
    {
        $prev = $this->makeSchedule(now()->addDays(5)->toDateString());
        $this->addPoint($prev, ['region' => 'central', 'pickup_location' => 'ปั๊ม ปตท. รังสิต', 'price' => 1650]);
        $this->addPoint($prev, ['region' => 'north', 'region_label' => 'ภาคเหนือ', 'pickup_location' => 'ขนส่งเชียงใหม่', 'price' => 1800]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules', $this->createSchedulePayload(now()->addDays(10)->toDateString()))
            ->assertCreated();

        $newId = $res->json('data.id');
        $this->assertDatabaseCount('schedule_pickup_points', 4); // 2 original + 2 copied
        $copied = SchedulePickupPoint::where('schedule_id', $newId)->get();
        $this->assertCount(2, $copied);
        $this->assertEqualsCanonicalizing([1650.0, 1800.0], $copied->pluck('price')->map(fn ($p) => (float) $p)->all());
        $this->assertSame('05:30', $copied->first()->pickup_time);
    }

    public function test_auto_copy_uses_most_recent_round_by_departure_date(): void
    {
        $older = $this->makeSchedule(now()->addDays(3)->toDateString());
        $this->addPoint($older, ['pickup_location' => 'จุดเก่า', 'price' => 1000]);

        $newer = $this->makeSchedule(now()->addDays(8)->toDateString());
        $this->addPoint($newer, ['pickup_location' => 'จุดใหม่', 'price' => 2000]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules', $this->createSchedulePayload(now()->addDays(15)->toDateString()))
            ->assertCreated();

        $copied = SchedulePickupPoint::where('schedule_id', $res->json('data.id'))->get();
        $this->assertCount(1, $copied);
        $this->assertSame('จุดใหม่', $copied->first()->pickup_location);
    }

    public function test_schedule_creates_fine_when_no_previous_round_has_points(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules', $this->createSchedulePayload(now()->addDays(2)->toDateString()))
            ->assertCreated()
            ->assertJsonPath('data.pickup_points', []);
    }

    public function test_copy_from_endpoint_copies_and_skips_duplicates(): void
    {
        $source = $this->makeSchedule(now()->addDays(4)->toDateString());
        $this->addPoint($source, ['region' => 'central', 'pickup_location' => 'ปั๊ม ปตท. รังสิต', 'price' => 1650]);
        $this->addPoint($source, ['region' => 'east', 'region_label' => 'ภาคตะวันออก', 'pickup_location' => 'บิ๊กซี ชลบุรี', 'price' => 1550]);

        $target = $this->makeSchedule(now()->addDays(9)->toDateString());
        // target already has one matching point (central/รังสิต) — should be skipped
        $this->addPoint($target, ['region' => 'central', 'pickup_location' => 'ปั๊ม ปตท. รังสิต', 'price' => 1650]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$target->id}/pickup-points/copy-from", ['source_schedule_id' => $source->id])
            ->assertOk()
            ->assertJsonPath('data.copied', 1);

        $this->assertSame(2, SchedulePickupPoint::where('schedule_id', $target->id)->count());
    }

    public function test_copy_from_same_schedule_is_rejected(): void
    {
        $s = $this->makeSchedule(now()->addDays(4)->toDateString());
        $this->addPoint($s);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$s->id}/pickup-points/copy-from", ['source_schedule_id' => $s->id])
            ->assertStatus(422);
    }

    public function test_sync_times_fills_every_other_round_of_the_trip(): void
    {
        // ทริปเดียวกันใช้เวลานัดชุดเดิมทุกรอบ — ไม่ต้องไล่แก้ทีละรอบทีละจุด
        $source = $this->makeSchedule(now()->addDays(5)->toDateString());
        $this->addPoint($source, ['pickup_location' => 'ปั๊ม ปตท. รังสิต', 'pickup_time' => '05:30']);
        $this->addPoint($source, ['region' => 'north', 'region_label' => 'ภาคเหนือ', 'pickup_location' => 'ขนส่งเชียงใหม่', 'pickup_time' => '06:15']);

        $target = $this->makeSchedule(now()->addDays(12)->toDateString());
        $rangsit = $this->addPoint($target, ['pickup_location' => 'ปั๊ม ปตท. รังสิต', 'pickup_time' => null]);
        $chiangmai = $this->addPoint($target, ['region' => 'north', 'region_label' => 'ภาคเหนือ', 'pickup_location' => 'ขนส่งเชียงใหม่', 'pickup_time' => '07:00']);
        // จุดที่ไม่มีคู่ในรอบต้นทางต้องไม่ถูกแตะ
        $orphan = $this->addPoint($target, ['pickup_location' => 'เซ็นทรัลลาดพร้าว', 'pickup_time' => '04:00']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules/'.$source->id.'/pickup-points/sync-times')
            ->assertOk()
            ->assertJsonPath('data.updated_schedules', 1)
            ->assertJsonPath('data.updated_points', 2);

        $this->assertSame('05:30', $rangsit->fresh()->pickup_time);
        $this->assertSame('06:15', $chiangmai->fresh()->pickup_time);
        $this->assertSame('04:00', $orphan->fresh()->pickup_time);
        // ไม่สร้างและไม่ลบจุดไหนเลย — ใบจองที่ผูกกับจุดรับเดิมจึงไม่ขยับ
        $this->assertSame(3, SchedulePickupPoint::where('schedule_id', $target->id)->count());
    }

    public function test_sync_times_never_reaches_another_trip(): void
    {
        $source = $this->makeSchedule(now()->addDays(5)->toDateString());
        $this->addPoint($source, ['pickup_time' => '05:30']);

        $otherTrip = Trip::create([
            'title' => 'ทริปน่าน', 'slug' => 'nan', 'type' => 'trekking', 'location' => 'Nan',
            'difficulty' => 'easy', 'duration_days' => 1, 'max_participants' => 20,
            'price_per_person' => 1500, 'status' => 'active',
        ]);
        $foreign = TripSchedule::create([
            'trip_id' => $otherTrip->id, 'departure_date' => now()->addDays(9)->toDateString(),
            'return_date' => now()->addDays(9)->toDateString(),
            'total_seats' => 12, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
        $foreignPoint = $this->addPoint($foreign, ['pickup_time' => '08:00']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules/'.$source->id.'/pickup-points/sync-times', [
                'schedule_ids' => [$foreign->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.updated_schedules', 0);

        $this->assertSame('08:00', $foreignPoint->fresh()->pickup_time);
    }

    public function test_sync_times_needs_a_time_on_the_source_round(): void
    {
        $source = $this->makeSchedule(now()->addDays(5)->toDateString());
        $this->addPoint($source, ['pickup_time' => null]);
        $this->makeSchedule(now()->addDays(12)->toDateString());

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules/'.$source->id.'/pickup-points/sync-times')
            ->assertStatus(422);
    }

    public function test_copy_sources_lists_other_rounds_with_points_only(): void
    {
        $withPoints = $this->makeSchedule(now()->addDays(4)->toDateString());
        $this->addPoint($withPoints);
        $this->makeSchedule(now()->addDays(6)->toDateString()); // no points — excluded
        $target = $this->makeSchedule(now()->addDays(9)->toDateString());

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/schedules/{$target->id}/pickup-points/copy-sources")
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame($withPoints->id, $res->json('data.0.id'));
        $this->assertSame(1, $res->json('data.0.pickup_points_count'));
    }
}
