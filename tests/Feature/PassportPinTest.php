<?php

namespace Tests\Feature;

use App\Jobs\DeleteMediaFilesJob;
use App\Jobs\GeneratePhotoThumbnailJob;
use App\Models\Booking;
use App\Models\PassportPin;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\PassportPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PassportPinTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake([GeneratePhotoThumbnailJob::class, DeleteMediaFilesJob::class]);
    }

    private function makeTrip(): Trip
    {
        return Trip::create([
            'title' => 'Doi '.uniqid(),
            'slug' => 'doi-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 2500,
            'status' => 'active',
            'latitude' => 18.5,
            'longitude' => 98.5,
        ]);
    }

    private function book(User $user, Trip $trip, int $daysAgo = 20): Booking
    {
        $departure = now('Asia/Bangkok')->subDays($daysAgo)->toDateString();

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $departure,
            'total_seats' => 10,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => sprintf('LLK-20200101-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'completed',
            'total_amount' => 2500,
            'paid_amount' => 2500,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'ยอดดอยหลวงเชียงดาว',
            'note' => 'ทะเลหมอกสวยที่สุด',
            'latitude' => 19.4,
            'longitude' => 98.88,
            'visited_on' => now('Asia/Bangkok')->subDays(3)->toDateString(),
            'source' => 'gps',
        ], $overrides);
    }

    public function test_user_can_drop_a_pin_with_photos(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/v1/me/passport/pins', $this->payload([
                'photos' => [
                    UploadedFile::fake()->image('a.jpg', 800, 600),
                    UploadedFile::fake()->image('b.jpg', 600, 800),
                ],
            ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'ยอดดอยหลวงเชียงดาว')
            ->assertJsonPath('data.source', 'gps')
            ->assertJsonPath('data.trip', null)
            ->assertJsonCount(2, 'data.photos');

        $pin = PassportPin::firstOrFail();
        $this->assertSame($user->id, $pin->user_id);
        $this->assertEqualsWithDelta(19.4, $pin->latitude, 0.0001);

        foreach ($pin->photos as $photo) {
            Storage::disk('public')->assertExists($photo->path);
            $this->assertStringStartsWith("passport-pins/{$user->id}/", $photo->path);
        }
        Queue::assertPushed(GeneratePhotoThumbnailJob::class, 2);
        $this->assertNotEmpty($response->json('data.photos.0.url'));
    }

    public function test_future_dates_and_bad_coordinates_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/me/passport/pins', $this->payload([
                'visited_on' => now('Asia/Bangkok')->addDays(2)->toDateString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('visited_on');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/me/passport/pins', $this->payload(['latitude' => 120]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('latitude');

        $this->assertSame(0, PassportPin::count());
    }

    public function test_pin_links_only_to_a_trip_the_user_has_finished(): void
    {
        $user = User::factory()->create();
        $walked = $this->makeTrip();
        $notWalked = $this->makeTrip();
        $this->book($user, $walked);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/me/passport/pins', $this->payload(['trip_id' => $notWalked->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'ผูกหมุดได้เฉพาะทริปที่คุณเดินทางจบแล้ว');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/me/passport/pins', $this->payload(['trip_id' => $walked->id, 'source' => 'trip']))
            ->assertCreated()
            ->assertJsonPath('data.trip.id', $walked->id);
    }

    public function test_owner_can_edit_add_and_remove_photos_and_delete(): void
    {
        $user = User::factory()->create();
        $pinId = $this->actingAs($user, 'sanctum')
            ->post('/api/v1/me/passport/pins', $this->payload([
                'photos' => [UploadedFile::fake()->image('a.jpg')],
            ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/me/passport/pins/{$pinId}", ['title' => 'จุดกางเต็นท์', 'note' => ''])
            ->assertOk()
            ->assertJsonPath('data.title', 'จุดกางเต็นท์')
            ->assertJsonPath('data.note', null)
            // ช่องที่ไม่ได้ส่งมาต้องไม่ถูกล้าง
            ->assertJsonPath('data.source', 'gps');

        $photos = $this->actingAs($user, 'sanctum')
            ->post("/api/v1/me/passport/pins/{$pinId}/photos", [
                'photos' => [UploadedFile::fake()->image('b.jpg')],
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(2, 'data.photos')
            ->json('data.photos');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/me/passport/pins/{$pinId}/photos/{$photos[0]['id']}")
            ->assertOk()
            ->assertJsonCount(1, 'data.photos');
        Queue::assertPushed(DeleteMediaFilesJob::class, 1);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/me/passport/pins/{$pinId}")
            ->assertOk();

        $this->assertSame(0, PassportPin::count());
        $this->assertDatabaseCount('passport_pin_photos', 0);
        // รูปที่เหลือถูกส่งไปลบไฟล์ด้วย ไม่ใช่แค่แถวหาย
        Queue::assertPushed(DeleteMediaFilesJob::class, 2);
    }

    public function test_photo_cap_per_pin_is_enforced(): void
    {
        $user = User::factory()->create();
        $files = array_map(
            fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 10, 10),
            range(1, PassportPinService::MAX_PHOTOS_PER_PIN),
        );

        $pinId = $this->actingAs($user, 'sanctum')
            ->post('/api/v1/me/passport/pins', $this->payload(['photos' => $files]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->post("/api/v1/me/passport/pins/{$pinId}/photos", [
                'photos' => [UploadedFile::fake()->image('extra.jpg')],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_pins_are_private_to_their_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $pinId = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/me/passport/pins', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/me/passport/pins')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($other, 'sanctum')
            ->putJson("/api/v1/me/passport/pins/{$pinId}", ['title' => 'x'])
            ->assertNotFound();

        $this->actingAs($other, 'sanctum')
            ->deleteJson("/api/v1/me/passport/pins/{$pinId}")
            ->assertNotFound();

        $this->assertSame(1, PassportPin::count());
    }

    public function test_conquest_map_carries_memories_without_mixing_them_into_trip_stats(): void
    {
        $user = User::factory()->create();
        $this->book($user, $this->makeTrip());

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/me/passport/pins', $this->payload([
                'photos' => [UploadedFile::fake()->image('a.jpg')],
            ]), ['Accept' => 'application/json'])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/passport/map')
            ->assertOk()
            ->assertJsonCount(1, 'data.pins')
            ->assertJsonCount(1, 'data.memories')
            ->assertJsonPath('data.memories.0.title', 'ยอดดอยหลวงเชียงดาว')
            ->assertJsonPath('data.summary.memories_count', 1)
            ->assertJsonPath('data.summary.memory_photos_count', 1)
            ->assertJsonPath('data.summary.trips_visited', 1);
    }

    public function test_deleting_the_account_sweeps_pin_photos(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/me/passport/pins', $this->payload([
                'photos' => [UploadedFile::fake()->image('a.jpg')],
            ]), ['Accept' => 'application/json'])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/auth/account')
            ->assertOk();

        $this->assertSame(0, PassportPin::count());
        Queue::assertPushed(DeleteMediaFilesJob::class, 1);
    }
}
