<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlipScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'test-key']);
    }

    /** Fake the Anthropic vision response with a given JSON body (prefill-aware). */
    private function fakeOcr(array $json): void
    {
        // The service prefills the assistant turn with "{", so the API only
        // returns the remainder of the object.
        $text = ltrim(json_encode($json, JSON_UNESCAPED_UNICODE), '{');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => $text]],
            ], 200),
        ]);
    }

    public function test_scan_returns_gregorian_date_and_time(): void
    {
        $this->fakeOcr([
            'status' => 'success',
            'amount' => 1500.0,
            'datetime' => '2026-06-16 14:30:00',
            'bank' => 'SCB',
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/payments/scan-slip', [
                'slip_image' => UploadedFile::fake()->image('slip.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('data.date', '2026-06-16')
            ->assertJsonPath('data.time', '14:30')
            ->assertJsonPath('data.amount', 1500);
    }

    public function test_scan_converts_buddhist_year_to_gregorian(): void
    {
        // Model echoes the slip's พ.ศ. year — service must subtract 543.
        $this->fakeOcr([
            'status' => 'success',
            'amount' => 990.0,
            'datetime' => '2569-06-16 09:05:00',
            'bank' => 'KBANK',
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/payments/scan-slip', [
                'slip_image' => UploadedFile::fake()->image('slip.png'),
            ])
            ->assertOk()
            ->assertJsonPath('data.date', '2026-06-16')
            ->assertJsonPath('data.time', '09:05');
    }

    public function test_scan_returns_422_when_datetime_unreadable(): void
    {
        $this->fakeOcr([
            'status' => 'unknown',
            'amount' => null,
            'datetime' => null,
            'bank' => null,
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/payments/scan-slip', [
                'slip_image' => UploadedFile::fake()->image('slip.jpg'),
            ])
            ->assertStatus(422);
    }

    public function test_scan_requires_authentication(): void
    {
        $this->postJson('/api/v1/payments/scan-slip', [
            'slip_image' => UploadedFile::fake()->image('slip.jpg'),
        ])->assertUnauthorized();
    }

    public function test_scan_validates_image_required(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/payments/scan-slip', [])
            ->assertStatus(422);
    }
}
