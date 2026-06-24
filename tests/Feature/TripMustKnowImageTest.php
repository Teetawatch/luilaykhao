<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * รูปภาพของรายการเสริม (must_know.items) — แอดมินแนบรูปต่อรายการได้ และรูปที่เคยใช้
 * จะถูกรวบรวมไว้ในคลังเพื่อนำกลับมาใช้ซ้ำกับทริปอื่น (เช่น รูปเต็นท์)
 */
class TripMustKnowImageTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeTrip(string $slug = 'mk-trip'): Trip
    {
        Category::firstOrCreate(['slug' => 'trekking'], ['name' => 'เดินป่า']);

        return Trip::create([
            'title' => 'Must Know Trip',
            'slug' => $slug,
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'region' => 'north',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 8,
            'price_per_person' => 1000,
            'status' => 'active',
        ]);
    }

    public function test_admin_can_save_an_image_url_on_a_must_know_item(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();

        $imageUrl = 'https://media.luilaykhao.com/media/tent.jpg';

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", [
                'title' => $trip->title,
                'type' => 'trekking',
                'location' => $trip->location,
                'region' => $trip->region,
                'difficulty' => 'easy',
                'duration_days' => 1,
                'max_participants' => 8,
                'price_per_person' => 1000,
                'must_know' => [
                    'items' => [
                        ['name' => 'เต็นท์', 'price' => 300, 'price_type' => 'per_booking', 'image_url' => $imageUrl],
                    ],
                    'remarks' => '',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.must_know.items.0.image_url', $imageUrl);

        $this->assertSame($imageUrl, $trip->fresh()->must_know['items'][0]['image_url']);
    }

    public function test_must_know_image_is_exposed_on_the_public_trip_resource(): void
    {
        $trip = $this->makeTrip();
        $trip->update([
            'must_know' => [
                'items' => [
                    ['name' => 'เต็นท์', 'price' => 300, 'price_type' => 'per_booking', 'image_url' => 'https://media.luilaykhao.com/media/tent.jpg'],
                ],
                'remarks' => '',
            ],
        ]);

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.must_know.items.0.image_url', 'https://media.luilaykhao.com/media/tent.jpg');
    }

    public function test_must_know_images_library_returns_distinct_used_images(): void
    {
        $admin = $this->makeAdmin();

        $tent = 'https://media.luilaykhao.com/media/tent.jpg';
        $bag = 'https://media.luilaykhao.com/media/bag.jpg';

        $tripA = $this->makeTrip('mk-a');
        $tripA->update(['must_know' => ['items' => [
            ['name' => 'เต็นท์', 'price' => 300, 'price_type' => 'per_booking', 'image_url' => $tent],
            ['name' => 'ถุงนอน', 'price' => 100, 'price_type' => 'per_person', 'image_url' => $bag],
        ]]]);

        // Another trip reusing the same tent image → must be de-duplicated.
        $tripB = $this->makeTrip('mk-b');
        $tripB->update(['must_know' => ['items' => [
            ['name' => 'เต็นท์ (รอบ 2)', 'price' => 350, 'price_type' => 'per_booking', 'image_url' => $tent],
            ['name' => 'ของแถมไม่มีรูป', 'price' => 0, 'price_type' => 'per_booking'],
        ]]]);

        $res = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/must-know/images')
            ->assertOk();

        $urls = collect($res->json('data'))->pluck('url')->all();

        $this->assertContains($tent, $urls);
        $this->assertContains($bag, $urls);
        $this->assertCount(2, $urls, 'duplicate image URLs should be collapsed');
    }
}
