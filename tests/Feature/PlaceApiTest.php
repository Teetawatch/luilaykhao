<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlaceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_place_index_returns_only_published_places(): void
    {
        Place::create(['name' => 'ภูกระดึง', 'slug' => 'phu-kradueng', 'status' => 'published']);
        Place::create(['name' => 'ที่ยังไม่เผยแพร่', 'slug' => 'draft-place', 'status' => 'draft']);

        $response = $this->getJson('/api/v1/places');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.places'));
        $this->assertSame('ภูกระดึง', $response->json('data.places.0.name'));
    }

    public function test_place_index_filters_by_month_and_skips_closed_places(): void
    {
        Place::create([
            'name' => 'เปิดเดือนมกรา',
            'slug' => 'open-jan',
            'status' => 'published',
            'best_months' => [1, 2],
        ]);

        // ระบุว่าควรไปเดือน 1 แต่ปิดเดือน 1 ด้วย — เดือนที่ปิดต้องชนะเสมอ
        Place::create([
            'name' => 'ปิดเดือนมกรา',
            'slug' => 'closed-jan',
            'status' => 'published',
            'best_months' => [1],
            'closed_months' => [1],
        ]);

        $response = $this->getJson('/api/v1/places?month=1');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.places'));
        $this->assertSame('open-jan', $response->json('data.places.0.slug'));
    }

    public function test_seasons_returns_all_twelve_months(): void
    {
        Place::create([
            'name' => 'ภูกระดึง',
            'slug' => 'phu-kradueng',
            'status' => 'published',
            'best_months' => [12],
            'closed_months' => [6, 7, 8, 9],
            'closure_note' => 'ปิดฟื้นฟูธรรมชาติ',
        ]);

        $response = $this->getJson('/api/v1/places/seasons');

        $response->assertOk();
        $this->assertCount(12, $response->json('data.months'));
        $this->assertSame('ภูกระดึง', $response->json('data.months.11.best.0.name'));
        $this->assertSame('ภูกระดึง', $response->json('data.months.5.closed.0.name'));
        $this->assertSame('winter', $response->json('data.months.11.season'));
        $this->assertSame('rainy', $response->json('data.months.5.season'));
    }

    public function test_place_detail_includes_linked_active_trips(): void
    {
        $place = Place::create(['name' => 'ภูชี้ฟ้า', 'slug' => 'phu-chi-fa', 'status' => 'published']);

        $trip = Trip::create([
            'title' => 'ทริปภูชี้ฟ้า',
            'slug' => 'trip-phu-chi-fa',
            'type' => 'trekking',
            'location' => 'เชียงราย',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);

        TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(20)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(21)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $place->trips()->attach($trip->id);

        $response = $this->getJson('/api/v1/places/phu-chi-fa');

        $response->assertOk();
        $this->assertSame('ทริปภูชี้ฟ้า', $response->json('data.trips.0.title'));
        $this->assertSame(1, $response->json('data.trips.0.upcoming_count'));
    }

    public function test_draft_place_is_not_publicly_reachable(): void
    {
        Place::create(['name' => 'ร่าง', 'slug' => 'draft-one', 'status' => 'draft']);

        $this->getJson('/api/v1/places/draft-one')->assertNotFound();
    }

    public function test_admin_can_create_place_with_thai_slug(): void
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/places', [
            'name' => 'ดอยหลวงเชียงดาว',
            'region' => 'north',
            'best_months' => [11, 12, 1],
            'status' => 'published',
        ]);

        $response->assertCreated();
        // slug ต้องเก็บอักษรไทยไว้ ไม่ถูก Str::slug ตัดจนเหลือค่าว่าง
        $this->assertSame('ดอยหลวงเชียงดาว', $response->json('data.slug'));
    }

    public function test_non_admin_cannot_create_place(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/admin/places', ['name' => 'ที่ใหม่'])
            ->assertForbidden();
    }
}
