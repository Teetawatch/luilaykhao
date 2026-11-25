<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * tracking/route — เส้นรถ→ลูกค้าของแอปรุ่นก่อน 2026-11-20 ที่ถามทุก ~25 วิต่อคน.
 * ตอนนี้ตอบว่างเสมอ (แอปวาดเส้นตรงแทน) และต้องไม่เรียก Google แม้มีคีย์
 */
class TrackingRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_old_app_route_answers_empty_without_calling_google(): void
    {
        config(['services.google_maps.api_key' => 'test-key']);
        Http::fake();

        $this->getJson('/api/v1/tracking/route?from_lat=13.75&from_lng=100.50&to_lat=13.80&to_lng=100.55')
            ->assertOk()
            ->assertJsonPath('data.polyline', '')
            ->assertJsonPath('data.distance', 0)
            ->assertJsonPath('data.duration', 0);

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('maps_api_usage')->count());
    }

    public function test_old_app_route_still_answers_without_query(): void
    {
        // แอปเก่าเช็กแค่ status 200 + polyline ว่าง = วาดเส้นตรง — ห้ามกลายเป็น 422/404
        $this->getJson('/api/v1/tracking/route')
            ->assertOk()
            ->assertJsonPath('data.polyline', '');
    }
}
