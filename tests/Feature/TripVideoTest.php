<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * วิดีโอทริป — แอดมินบันทึก URL วิดีโอ (เก็บใน R2) และ TripResource ส่งกลับให้แอปแสดง
 */
class TripVideoTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeTrip(): Trip
    {
        Category::firstOrCreate(['slug' => 'trekking'], ['name' => 'เดินป่า']);

        return Trip::create([
            'title' => 'Video Trip',
            'slug' => 'video-trip',
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

    public function test_admin_can_save_videos_on_a_trip(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();

        $videos = [
            'https://media.luilaykhao.com/media/clip-a.mp4',
            'https://media.luilaykhao.com/media/clip-b.mp4',
        ];

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
                'videos' => $videos,
            ])
            ->assertOk()
            ->assertJsonPath('data.videos', $videos);

        $this->assertSame($videos, $trip->fresh()->videos);
    }

    public function test_videos_are_exposed_on_the_public_trip_resource(): void
    {
        $trip = $this->makeTrip();
        $trip->update(['videos' => ['https://media.luilaykhao.com/media/clip.mp4']]);

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.videos', ['https://media.luilaykhao.com/media/clip.mp4']);
    }

    public function test_videos_must_be_an_array_of_strings(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();

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
                'videos' => [['not' => 'a string']],
            ])
            ->assertStatus(422);
    }

    private function updatePayload(Trip $trip, array $extra): array
    {
        return array_merge([
            'title' => $trip->title,
            'type' => 'trekking',
            'location' => $trip->location,
            'region' => $trip->region,
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 8,
            'price_per_person' => 1000,
        ], $extra);
    }

    public function test_admin_can_pick_a_cover_video(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();
        $clip = 'https://media.luilaykhao.com/media/cover.mp4';

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", $this->updatePayload($trip, [
                'videos' => [$clip],
                'cover_video' => $clip,
            ]))
            ->assertOk()
            ->assertJsonPath('data.cover_video', $clip);

        $this->assertSame($clip, $trip->fresh()->cover_video);

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.cover_video', $clip);
    }

    public function test_clearing_the_cover_video_falls_back_to_the_cover_image(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();
        $trip->update(['cover_video' => 'https://media.luilaykhao.com/media/cover.mp4']);

        // แอดมินส่งสตริงว่างตอนเอาวิดีโอหน้าปกออก
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", $this->updatePayload($trip, [
                'cover_video' => '',
            ]))
            ->assertOk()
            ->assertJsonPath('data.cover_video', null);

        $this->assertNull($trip->fresh()->cover_video);
    }

    public function test_trip_without_a_cover_video_sends_null(): void
    {
        $trip = $this->makeTrip();

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.cover_video', null);
    }
}
