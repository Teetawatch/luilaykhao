<?php

namespace Tests\Feature;

use App\Jobs\GenerateTripCardImageJob;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * รูปการ์ดทริปที่ย่อแล้ว — การ์ดหน้าแรกเคยโหลดรูปปกขนาดเต็ม
 */
class TripCardImageTest extends TestCase
{
    use RefreshDatabase;

    private function bigJpeg(string $path, int $w = 2400, int $h = 1600): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 200));
        ob_start();
        imagejpeg($img, null, 90);
        $data = ob_get_clean();
        imagedestroy($img);
        Storage::disk('public')->put($path, $data);

        return Storage::disk('public')->url($path);
    }

    private function trip(array $attrs = []): Trip
    {
        return Trip::create([
            'title' => 'Card Trip',
            'slug' => 'card-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
            ...$attrs,
        ]);
    }

    public function test_saving_a_trip_builds_a_small_card_image(): void
    {
        Storage::fake('public');
        $cover = $this->bigJpeg('media/cover.jpg');

        $trip = $this->trip(['cover_image' => $cover])->fresh();

        $this->assertNotNull($trip->card_image);
        $this->assertSame($cover, $trip->card_image_source);
        $path = GenerateTripCardImageJob::diskPathFor($trip->card_image, Storage::disk('public')->url(''));
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame(GenerateTripCardImageJob::MAX_EDGE, max($w, $h));

        $this->getJson('/api/v1/trips')->assertJsonPath('data.0.card_image', $trip->card_image);
    }

    public function test_thumbnail_image_wins_over_cover_and_a_change_rebuilds(): void
    {
        Storage::fake('public');
        $cover = $this->bigJpeg('media/cover.jpg');
        $thumb = $this->bigJpeg('media/thumb.jpg', 1200, 1200);

        $trip = $this->trip(['cover_image' => $cover, 'thumbnail_image' => $thumb])->fresh();
        $this->assertSame($thumb, $trip->card_image_source);
        $first = $trip->card_image;

        $other = $this->bigJpeg('media/other.jpg');
        $trip->update(['thumbnail_image' => $other]);

        $trip->refresh();
        $this->assertSame($other, $trip->card_image_source);
        $this->assertNotSame($first, $trip->card_image);
    }

    public function test_stale_card_image_is_not_served_after_the_picture_changes(): void
    {
        $trip = $this->trip(['cover_image' => 'https://elsewhere.example/a.jpg']);
        $trip->forceFill(['card_image' => 'https://cdn/x.jpg', 'card_image_source' => 'https://elsewhere.example/old.jpg'])->saveQuietly();

        $this->assertNull($trip->fresh()->cardImageUrl());
    }

    public function test_external_pictures_are_left_alone(): void
    {
        Storage::fake('public');
        $trip = $this->trip(['cover_image' => 'https://images.example.com/a.jpg'])->fresh();

        $this->assertNull($trip->card_image);
        $this->assertNull(GenerateTripCardImageJob::diskPathFor('https://images.example.com/a.jpg', 'https://cdn.luilaykhao.com'));
        $this->assertNull(GenerateTripCardImageJob::diskPathFor('https://cdn.luilaykhao.com/../etc/passwd', 'https://cdn.luilaykhao.com'));
        $this->assertSame('media/a b.jpg', GenerateTripCardImageJob::diskPathFor('https://cdn.luilaykhao.com/media/a%20b.jpg?v=2', 'https://cdn.luilaykhao.com/'));
    }

    public function test_backfill_command_covers_existing_trips(): void
    {
        Storage::fake('public');
        $cover = $this->bigJpeg('media/cover.jpg');
        $trip = $this->trip();
        Trip::whereKey($trip->id)->update(['cover_image' => $cover]); // ไม่ผ่าน observer = ทริปเก่า

        $this->artisan('trips:card-images', ['--sync' => true])->assertSuccessful();

        $this->assertSame($cover, $trip->fresh()->card_image_source);
    }
}
