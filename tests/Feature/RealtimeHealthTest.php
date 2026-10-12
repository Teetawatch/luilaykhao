<?php

namespace Tests\Feature;

use App\Services\OpsHealthService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * เกิดจาก 2026-10-12: event อัปเดตสดทุกตัว (แชต ที่นั่ง ชำระเงิน) ล้มเหลวในคิว
 * เพราะ Laravel ส่งเข้า Reverb ไม่ได้ แอปยังใช้ได้ปกติจึงไม่มีใครรู้ นอกจากคนที่เปิด
 * /horizon/failed — ซึ่งบอกแค่ว่าล้ม ไม่บอกว่าเพราะอะไร
 */
class RealtimeHealthTest extends TestCase
{
    /** ให้ Reverb ตอบตามที่กำหนด — ผ่าน Guzzle handler เพราะ Pusher ไม่ได้ใช้ Http:: */
    private function reverbAnswers(Response|\Throwable $answer): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb',
                'key' => 'k', 'secret' => 's', 'app_id' => 'a',
                'options' => ['host' => 'luilaykhao.com', 'port' => 443, 'scheme' => 'https', 'useTLS' => true],
                'client_options' => ['handler' => HandlerStack::create(new MockHandler([$answer]))],
            ],
        ]);
    }

    private function probe(): array
    {
        return app(OpsHealthService::class)->probeRealtime();
    }

    public function test_a_publish_that_reaches_reverb_is_ok(): void
    {
        $this->reverbAnswers(new Response(200, [], '{}'));

        $probe = $this->probe();

        $this->assertSame(OpsHealthService::OK, $probe['status']);
        $this->assertSame('https://luilaykhao.com:443', $probe['target']);
    }

    public function test_an_unreachable_reverb_fails_and_points_at_the_process_and_env(): void
    {
        $this->reverbAnswers(new ConnectException('cURL error 7: Failed to connect', new Request('POST', '/')));

        $probe = $this->probe();

        $this->assertSame(OpsHealthService::FAIL, $probe['status']);
        $this->assertStringContainsString('Failed to connect', $probe['detail']);
        $this->assertStringContainsString('supervisorctl status reverb', $probe['hint']);
    }

    public function test_a_proxy_that_is_not_reverb_says_so(): void
    {
        $this->reverbAnswers(new Response(404, [], '<html>Not Found</html>'));

        $probe = $this->probe();

        $this->assertSame(OpsHealthService::FAIL, $probe['status']);
        $this->assertStringContainsString('ไม่ใช่ Reverb', $probe['hint']);
        $this->assertStringContainsString('127.0.0.1', $probe['hint']);
    }

    public function test_a_wrong_secret_says_so(): void
    {
        $this->reverbAnswers(new Response(401, [], 'Authentication signature invalid.'));

        $probe = $this->probe();

        $this->assertSame(OpsHealthService::FAIL, $probe['status']);
        $this->assertStringContainsString('REVERB_APP_ID/KEY/SECRET', $probe['hint']);
    }

    public function test_broadcasting_switched_off_is_only_a_warning(): void
    {
        config(['broadcasting.default' => 'null']);

        $this->assertSame(OpsHealthService::WARN, $this->probe()['status']);
    }

    public function test_the_check_appears_in_ops_doctor(): void
    {
        $this->reverbAnswers(new Response(404, [], 'nope'));

        $check = collect(app(OpsHealthService::class)->checks())->firstWhere('key', 'realtime');

        $this->assertSame(OpsHealthService::FAIL, $check['status']);
        $this->assertStringContainsString('ไม่ใช่ Reverb', $check['detail']);
    }

    public function test_reverb_check_command_exits_non_zero_on_failure(): void
    {
        $this->reverbAnswers(new Response(401, [], 'Authentication signature invalid.'));

        $this->artisan('reverb:check')
            ->expectsOutputToContain('REVERB_APP_ID/KEY/SECRET')
            ->assertFailed();
    }

    public function test_reverb_check_command_passes_when_reverb_answers(): void
    {
        $this->reverbAnswers(new Response(200, [], '{}'));

        $this->artisan('reverb:check')->assertSuccessful();
    }
}
