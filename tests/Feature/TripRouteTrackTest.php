<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\User;
use App\Services\RouteTrackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TripRouteTrackTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function trip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'Doi Test',
            'slug' => 'doi-test-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 2500,
            'status' => 'active',
        ], $overrides));
    }

    /** ทางขึ้นเนินสั้น ๆ ที่ระยะกับความสูงคำนวณตรวจสอบได้ */
    private function gpx(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">
  <trk><name>Test</name><trkseg>
    <trkpt lat="18.5000" lon="98.5000"><ele>500</ele></trkpt>
    <trkpt lat="18.5050" lon="98.5000"><ele>560</ele></trkpt>
    <trkpt lat="18.5100" lon="98.5000"><ele>700</ele></trkpt>
    <trkpt lat="18.5150" lon="98.5000"><ele>660</ele></trkpt>
    <trkpt lat="18.5200" lon="98.5000"><ele>900</ele></trkpt>
  </trkseg></trk>
</gpx>
XML;
    }

    public function test_parser_computes_distance_gain_and_loss(): void
    {
        $track = app(RouteTrackService::class)->fromGpx($this->gpx());

        // 4 ช่วง × ~0.0050° latitude ≈ 0.556 กม. ต่อช่วง
        $this->assertEqualsWithDelta(2.22, $track['distance_km'], 0.05);
        // ไต่ 60 + 140 + 240 = 440, ลง 40
        $this->assertSame(440, $track['elevation_gain_m']);
        $this->assertSame(40, $track['elevation_loss_m']);
        $this->assertSame(900, $track['max_elevation_m']);
        $this->assertSame(500, $track['min_elevation_m']);
        $this->assertTrue($track['has_elevation']);
        $this->assertCount(5, $track['points']);
        // แกน X ของกราฟเป็นระยะทางจริง ไม่ใช่ลำดับจุด
        $this->assertSame(0.0, $track['points'][0]['km']);
        $this->assertGreaterThan(0, $track['points'][4]['km']);
    }

    public function test_parser_ignores_elevation_noise_below_threshold(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">
  <trk><trkseg>
    <trkpt lat="18.5000" lon="98.5000"><ele>500</ele></trkpt>
    <trkpt lat="18.5010" lon="98.5000"><ele>501</ele></trkpt>
    <trkpt lat="18.5020" lon="98.5000"><ele>500</ele></trkpt>
    <trkpt lat="18.5030" lon="98.5000"><ele>502</ele></trkpt>
  </trkseg></trk>
</gpx>
XML;

        $track = app(RouteTrackService::class)->fromGpx($xml);

        // ขยับขึ้นลงทีละ 1–2 ม. คือสัญญาณรบกวน ไม่ใช่การไต่
        $this->assertSame(0, $track['elevation_gain_m']);
        $this->assertSame(0, $track['elevation_loss_m']);
    }

    public function test_parser_rejects_a_file_with_no_points(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(RouteTrackService::class)->fromGpx(
            '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"></gpx>'
        );
    }

    public function test_long_tracks_are_downsampled_but_keep_both_ends(): void
    {
        $points = [];
        for ($i = 0; $i < 1000; $i++) {
            $points[] = ['lat' => 18.5 + $i * 0.0001, 'lng' => 98.5, 'ele' => 500.0 + $i];
        }

        $track = app(RouteTrackService::class)->build($points);

        $this->assertSame(1000, $track['point_count']);
        $this->assertLessThanOrEqual(RouteTrackService::MAX_POINTS + 1, count($track['points']));
        $this->assertSame(0.0, $track['points'][0]['km']);
        $this->assertSame(1499, $track['points'][count($track['points']) - 1]['ele']);
    }

    public function test_admin_upload_stores_the_track_and_backfills_empty_stats(): void
    {
        $admin = $this->admin();
        $trip = $this->trip(['distance_km' => null, 'elevation_gain_m' => null]);

        $this->actingAs($admin, 'sanctum')
            ->post("/api/v1/admin/trips/{$trip->id}/route-track", [
                'gpx' => UploadedFile::fake()->createWithContent('route.gpx', $this->gpx()),
            ])
            ->assertOk()
            ->assertJsonPath('data.elevation_gain_m', 440);

        $trip->refresh();
        $this->assertNotNull($trip->route_track);
        $this->assertSame(440, $trip->elevation_gain_m);
        $this->assertEqualsWithDelta(2.22, (float) $trip->distance_km, 0.05);
    }

    public function test_admin_upload_does_not_overwrite_numbers_already_entered(): void
    {
        $admin = $this->admin();
        $trip = $this->trip(['distance_km' => 12.5, 'elevation_gain_m' => 999]);

        $this->actingAs($admin, 'sanctum')
            ->post("/api/v1/admin/trips/{$trip->id}/route-track", [
                'gpx' => UploadedFile::fake()->createWithContent('route.gpx', $this->gpx()),
            ])
            ->assertOk();

        $trip->refresh();
        $this->assertSame(999, $trip->elevation_gain_m);
        $this->assertEqualsWithDelta(12.5, (float) $trip->distance_km, 0.001);
    }

    public function test_upload_rejects_non_gpx_files(): void
    {
        $admin = $this->admin();
        $trip = $this->trip();

        $this->actingAs($admin, 'sanctum')
            ->post("/api/v1/admin/trips/{$trip->id}/route-track", [
                'gpx' => UploadedFile::fake()->createWithContent('route.txt', 'not gpx'),
            ])
            ->assertStatus(422);
    }

    public function test_route_track_is_exposed_on_the_public_trip_endpoint(): void
    {
        $trip = $this->trip();
        $trip->route_track = app(RouteTrackService::class)->fromGpx($this->gpx());
        $trip->save();

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.route_track.elevation_gain_m', 440);
    }

    public function test_upload_requires_an_admin(): void
    {
        $trip = $this->trip();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->post("/api/v1/admin/trips/{$trip->id}/route-track", [
                'gpx' => UploadedFile::fake()->createWithContent('route.gpx', $this->gpx()),
            ])
            ->assertForbidden();
    }
}
