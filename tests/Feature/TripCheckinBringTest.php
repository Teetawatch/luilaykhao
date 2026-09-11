<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "สิ่งที่ต้องพกไปวันเดินทาง" ต่อทริป — แอดมินพิมพ์เอง เว้นว่าง = ไม่ต้องพกอะไร
 *
 * ข้อความคืนก่อนเดินทางในห้องแชทอ่านค่านี้ (ดู TripChatTimelineTest)
 */
class TripCheckinBringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        Category::create(['name' => 'เดินป่า', 'slug' => 'trekking']);
    }

    private function makeTrip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'ภูกระดึง',
            'slug' => 'trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 2500,
            'status' => 'active',
        ], $overrides));
    }

    private function payload(Trip $trip, array $extra): array
    {
        return array_merge([
            'title' => $trip->title,
            'type' => 'trekking',
            'location' => $trip->location,
            'region' => $trip->region,
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 2500,
        ], $extra);
    }

    public function test_admin_can_save_what_travellers_must_bring(): void
    {
        $trip = $this->makeTrip();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", $this->payload($trip, [
                'checkin_bring' => 'พาสปอร์ต',
            ]))
            ->assertOk();

        $this->assertSame('พาสปอร์ต', $trip->fresh()->checkinBringNote());
    }

    public function test_admin_can_clear_it_for_a_trip_that_needs_nothing(): void
    {
        $trip = $this->makeTrip(['checkin_bring' => 'บัตรประชาชน']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", $this->payload($trip, [
                'checkin_bring' => '',
            ]))
            ->assertOk();

        $this->assertNull($trip->fresh()->checkinBringNote());
    }

    public function test_public_trip_exposes_the_note(): void
    {
        $trip = $this->makeTrip(['checkin_bring' => 'บัตรประชาชน']);

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.checkin_bring', 'บัตรประชาชน');
    }
}
