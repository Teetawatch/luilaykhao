<?php

namespace Tests\Feature;

use App\Events\ChatDropoffUpdated;
use App\Models\ChatDropoffChoice;
use App\Models\ChatMessage;
use App\Models\SchedulePickupPoint;
use App\Models\ScheduleVehicleOption;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Tests\Feature\Concerns\TripChatFixtures;
use Tests\TestCase;

/**
 * ขอลงระหว่างทางขากลับ — จุดลงรายคน, เรียงตามทางจากตำแหน่งรถ, เตือนก่อนถึง
 */
class ChatDropoffTest extends TestCase
{
    use RefreshDatabase;
    use TripChatFixtures;

    private Vehicle $van;

    private SchedulePickupPoint $rangsit;

    private SchedulePickupPoint $mochit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTripChatPushes();
        // ไม่ใช้แคชพิกัดรถจาก Redis ของเครื่อง dev — อ่านจากฐานข้อมูลเทสเท่านั้น
        Redis::shouldReceive('get')->andReturn(null);
    }

    private function roundWithPoints(): TripSchedule
    {
        $this->van = Vehicle::create(['name' => 'รถตู้ 1', 'type' => 'van', 'capacity' => 12, 'license_plate' => 'กข 1234']);
        $schedule = $this->tripSchedule(['vehicle_id' => $this->van->id]);

        $this->rangsit = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id, 'region' => 'bkk', 'region_label' => 'กรุงเทพฯ',
            'price' => 0, 'pickup_location' => 'ปั๊ม ปตท. รังสิต', 'latitude' => 14.0390, 'longitude' => 100.6150, 'sort_order' => 2,
        ]);
        $this->mochit = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id, 'region' => 'bkk', 'region_label' => 'กรุงเทพฯ',
            'price' => 0, 'pickup_location' => 'หมอชิต', 'latitude' => 13.8020, 'longitude' => 100.5530, 'sort_order' => 1,
        ]);

        return $schedule;
    }

    private function vanAt(float $lat, float $lng, ?Vehicle $vehicle = null): void
    {
        VehicleLocation::create([
            'vehicle_id' => ($vehicle ?? $this->van)->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'recorded_at' => now(),
        ]);
    }

    private function open(User $staff, TripSchedule $schedule)
    {
        return $this->actingAs($staff, 'sanctum')->postJson($this->chatUrl($schedule, 'dropoffs'));
    }

    private function choose(User $user, TripSchedule $schedule, int $id, array $payload)
    {
        return $this->actingAs($user, 'sanctum')->putJson($this->chatUrl($schedule, "dropoffs/{$id}/choice"), $payload);
    }

    public function test_everyone_starts_at_their_pickup_point_and_join_trip_is_left_out(): void
    {
        $schedule = $this->roundWithPoints();
        [$family, , [$dad]] = $this->party($schedule, ['พ่อ ใจดี'], ['pickup_point_id' => $this->rangsit->id]);
        $this->party($schedule, ['จอย ขับเอง'], ['is_join_trip' => true]);
        $staff = $this->staffOf($schedule);

        $this->open($family, $schedule)->assertForbidden();
        $data = $this->open($staff, $schedule)->assertCreated()->json('data');
        $this->assertStringContainsString('ขากลับลงที่ไหน', $data['body']);
        $this->assertSame(1, $data['dropoff']['total']);
        $this->assertSame(1, ChatDropoffChoice::count());

        // มีการ์ดเปิดอยู่แล้ว
        $this->open($staff, $schedule)->assertStatus(422);

        $id = $data['dropoff']['id'];
        $show = $this->actingAs($family, 'sanctum')->getJson($this->chatUrl($schedule, "dropoffs/{$id}"))->assertOk()->json('data');
        $this->assertSame('ปั๊ม ปตท. รังสิต', $show['dropoff']['mine'][0]['label']);
        $this->assertTrue($show['dropoff']['mine'][0]['is_default']);
        $this->assertSame($dad->id, $show['dropoff']['mine'][0]['id']);
        $this->assertSame(['หมอชิต', 'ปั๊ม ปตท. รังสิต'], array_column($show['options'], 'label'));
        $this->assertArrayNotHasKey('detail', $show['dropoff']);
    }

    public function test_choosing_a_point_or_a_custom_spot_and_privacy_of_custom_spots(): void
    {
        $schedule = $this->roundWithPoints();
        [$family, , [$dad, $mom]] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี'], ['pickup_point_id' => $this->rangsit->id]);
        [$solo, , [$mana]] = $this->party($schedule, ['มานะ ขยัน'], ['pickup_point_id' => $this->mochit->id]);
        $staff = $this->staffOf($schedule);
        $id = $this->open($staff, $schedule)->json('data.dropoff.id');

        $this->choose($solo, $schedule, $id, ['passenger_ids' => [$dad->id], 'pickup_point_id' => $this->mochit->id])->assertStatus(422);

        $mine = $this->choose($family, $schedule, $id, ['passenger_ids' => [$mom->id], 'pickup_point_id' => $this->mochit->id])
            ->assertOk()->json('data.dropoff.mine');
        $this->assertSame(['ปั๊ม ปตท. รังสิต', 'หมอชิต'], array_column($mine, 'label'));

        Event::fake([ChatDropoffUpdated::class]);
        $this->choose($solo, $schedule, $id, [
            'passenger_ids' => [$mana->id], 'label' => 'หน้าบ้านซอยลาดพร้าว 71', 'lat' => 13.7000, 'lng' => 100.5000, 'note' => 'บ้านประตูสีเขียว',
        ])->assertOk()->assertJsonPath('data.dropoff.mine.0.is_default', false);

        // ที่อยู่บ้านไม่ออกไปถึงห้องรวมหรือคนอื่น
        Event::assertDispatched(ChatDropoffUpdated::class, fn ($e) => ! str_contains(json_encode($e->payload, JSON_UNESCAPED_UNICODE), 'ลาดพร้าว'));
        $other = $this->actingAs($family, 'sanctum')->getJson($this->chatUrl($schedule, "dropoffs/{$id}"))->json('data');
        $this->assertStringNotContainsString('ลาดพร้าว', json_encode($other['dropoff'], JSON_UNESCAPED_UNICODE));

        // ทีมงานได้จุดเรียงตามทางจากตำแหน่งรถ (รถอยู่อยุธยา → รังสิต → หมอชิต → ลาดพร้าว)
        $this->vanAt(14.20, 100.60);
        $detail = $this->actingAs($staff, 'sanctum')->getJson($this->chatUrl($schedule, "dropoffs/{$id}"))->json('data.dropoff.detail');
        $this->assertSame(['ปั๊ม ปตท. รังสิต', 'หมอชิต', 'หน้าบ้านซอยลาดพร้าว 71'], array_column($detail['stops'], 'label'));
        $this->assertSame([1, 1, 1], array_column($detail['stops'], 'count'));
        $this->assertSame('บ้านประตูสีเขียว', $detail['stops'][2]['passengers'][0]['note']);
        $this->assertNotNull($detail['van']);

        // กลับไปใช้จุดรับเดิม
        $this->choose($solo, $schedule, $id, ['passenger_ids' => [$mana->id]])->assertOk()
            ->assertJsonPath('data.dropoff.mine.0.label', 'หมอชิต')
            ->assertJsonPath('data.dropoff.mine.0.is_default', true);
    }

    public function test_people_get_ready_when_their_van_comes_close_once(): void
    {
        $schedule = $this->roundWithPoints();
        [$family] = $this->party($schedule, ['พ่อ ใจดี'], ['pickup_point_id' => $this->rangsit->id]);
        [$solo] = $this->party($schedule, ['มานะ ขยัน'], ['pickup_point_id' => $this->mochit->id]);
        $staff = $this->staffOf($schedule);
        $this->open($staff, $schedule)->assertCreated();

        $this->vanAt(14.20, 100.60);
        $this->settleChat();
        $this->assertSame([], $this->pushes, 'ยังไกล');

        $this->vanAt(14.05, 100.61);
        $this->settleChat();
        $this->settleChat();

        $this->assertCount(1, $this->pushesTo($family));
        $this->assertSame('🚐 ใกล้ถึงจุดลงของคุณแล้ว', $this->pushesTo($family)[0]['title']);
        $this->assertStringContainsString('ปั๊ม ปตท. รังสิต', $this->pushesTo($family)[0]['body']);
        $this->assertSame([], $this->pushesTo($solo));
        $this->assertSame('🚐 ใกล้ถึง ปั๊ม ปตท. รังสิต — ลง 1 คน', $this->pushesTo($staff)[0]['title']);
    }

    public function test_bus_passengers_follow_the_bus_not_the_van(): void
    {
        $schedule = $this->roundWithPoints();
        $bus = Vehicle::create(['name' => 'รถบัส', 'type' => 'bus', 'capacity' => 40, 'license_plate' => 'บข 9999']);
        $option = ScheduleVehicleOption::create([
            'schedule_id' => $schedule->id, 'label' => 'รถบัส', 'transport_type' => 'bus', 'vehicle_id' => $bus->id,
            'price_adjustment' => 0, 'is_active' => true, 'sort_order' => 1,
        ]);
        [$onBus] = $this->party($schedule, ['คนบนบัส'], ['pickup_point_id' => $this->rangsit->id, 'vehicle_option_id' => $option->id]);
        [$onVan] = $this->party($schedule, ['คนบนตู้'], ['pickup_point_id' => $this->rangsit->id]);
        $staff = $this->staffOf($schedule);
        $this->open($staff, $schedule)->assertCreated();

        // รถตู้ถึงรังสิตแล้ว แต่บัสยังอยู่ไกล
        $this->vanAt(14.04, 100.615);
        $this->vanAt(15.50, 101.00, $bus);
        $this->settleChat();

        $this->assertCount(1, $this->pushesTo($onVan));
        $this->assertSame([], $this->pushesTo($onBus));
    }

    public function test_staff_drop_stop_by_stop_and_the_card_closes_when_everyone_is_off(): void
    {
        $schedule = $this->roundWithPoints();
        [$family, , [$dad, $mom]] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี'], ['pickup_point_id' => $this->rangsit->id]);
        [, , [$mana]] = $this->party($schedule, ['มานะ ขยัน'], ['pickup_point_id' => $this->mochit->id]);
        $staff = $this->staffOf($schedule);
        $id = $this->open($staff, $schedule)->json('data.dropoff.id');
        $drop = fn (array $ids, bool $dropped = true) => $this->actingAs($staff, 'sanctum')
            ->postJson($this->chatUrl($schedule, "dropoffs/{$id}/drop"), ['passenger_ids' => $ids, 'dropped' => $dropped]);

        $this->actingAs($family, 'sanctum')->postJson($this->chatUrl($schedule, "dropoffs/{$id}/drop"), ['passenger_ids' => [$dad->id], 'dropped' => true])
            ->assertForbidden();

        $drop([$dad->id, $mom->id])->assertOk()
            ->assertJsonPath('data.dropoff.dropped_count', 2)
            ->assertJsonPath('data.dropoff.stops_done', 1);

        // ลงไปแล้วเปลี่ยนจุดไม่ได้
        $this->choose($family, $schedule, $id, ['passenger_ids' => [$dad->id], 'pickup_point_id' => $this->mochit->id])->assertStatus(422);

        $drop([$mana->id])->assertOk()->assertJsonPath('data.dropoff.is_closed', true);
        $this->assertTrue(ChatMessage::where('body', '🚐 ส่งครบทุกจุดแล้ว (3 คน) ขอบคุณที่เดินทางกับเราครับ 🙏')->exists());
    }
}
