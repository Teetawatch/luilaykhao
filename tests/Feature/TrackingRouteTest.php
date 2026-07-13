<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrackingRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_route_returns_road_polyline_from_directions_api(): void
    {
        config(['services.google_maps.api_key' => 'test-key']);

        Http::fake([
            'maps.googleapis.com/maps/api/directions/*' => Http::response([
                'status' => 'OK',
                'routes' => [[
                    'overview_polyline' => ['points' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@'],
                    'legs' => [[
                        'distance' => ['value' => 12345],
                        'duration' => ['value' => 900],
                    ]],
                ]],
            ], 200),
        ]);

        $this->getJson('/api/v1/tracking/route?from_lat=13.75&from_lng=100.50&to_lat=13.80&to_lng=100.55')
            ->assertOk()
            ->assertJsonPath('data.polyline', '_p~iF~ps|U_ulLnnqC_mqNvxq`@')
            ->assertJsonPath('data.distance', 12345)
            ->assertJsonPath('data.duration', 900);
    }

    public function test_route_falls_back_to_empty_polyline_without_api_key(): void
    {
        config(['services.google_maps.api_key' => '']);

        $this->getJson('/api/v1/tracking/route?from_lat=13.75&from_lng=100.50&to_lat=13.80&to_lng=100.55')
            ->assertOk()
            ->assertJsonPath('data.polyline', '');
    }

    public function test_route_validates_coordinates(): void
    {
        $this->getJson('/api/v1/tracking/route?from_lat=999&from_lng=100.50&to_lat=13.80&to_lng=100.55')
            ->assertStatus(422);
    }
}
