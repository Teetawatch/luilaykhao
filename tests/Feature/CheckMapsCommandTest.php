<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GoogleRoutesClient คืน null เงียบ ๆ ทุกกรณี — maps:check ต้องบอกได้ว่าเพราะอะไร
 */
class CheckMapsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_key_says_so(): void
    {
        config(['services.google_maps.api_key' => '']);

        $this->artisan('maps:check')
            ->expectsOutputToContain('ไม่มีคีย์')
            ->assertFailed();
    }

    public function test_a_working_key_passes(): void
    {
        config(['services.google_maps.api_key' => 'AIzaTestKey123456']);
        Http::fake(['routes.googleapis.com/*' => Http::response([
            'routes' => [['distanceMeters' => 7200, 'duration' => '1260s', 'polyline' => ['encodedPolyline' => 'x'], 'legs' => []]],
        ])]);

        $this->artisan('maps:check')
            ->expectsOutputToContain('Routes API ใช้ได้')
            ->assertSuccessful();
    }

    public function test_an_ip_restriction_is_explained(): void
    {
        config(['services.google_maps.api_key' => 'AIzaTestKey123456']);
        Http::fake(['routes.googleapis.com/*' => Http::response(['error' => [
            'code' => 403,
            'status' => 'PERMISSION_DENIED',
            'message' => 'The provided API key has an IP address restriction. The originating IP address of the call (203.0.113.9) violates this restriction.',
        ]], 403)]);

        $this->artisan('maps:check')
            ->expectsOutputToContain('IP ของเครื่องนี้ไม่อยู่ใน Application restrictions')
            ->expectsOutputToContain('203.0.113.9')
            ->assertFailed();
    }

    public function test_a_disabled_api_is_explained(): void
    {
        config(['services.google_maps.api_key' => 'AIzaTestKey123456']);
        Http::fake(['routes.googleapis.com/*' => Http::response(['error' => [
            'code' => 403,
            'status' => 'PERMISSION_DENIED',
            'message' => 'Routes API has not been used in project 123 before or it is disabled.',
        ]], 403)]);

        $this->artisan('maps:check')
            ->expectsOutputToContain('ยังไม่ได้เปิด Routes API')
            ->assertFailed();
    }
}
