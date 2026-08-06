<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\LiveActivity;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ApnsLiveActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * การคุยกับ APNs ตรง ๆ สำหรับ Live Activity
 *
 * ส่วนที่เปราะที่สุดของฟีเจอร์นี้คือลายเซ็น JWT: openssl เซ็น ECDSA ออกมาเป็น DER
 * แต่ JWS ES256 ต้องการ r‖s ดิบ ๆ ถ้าลืมแปลง APNs จะตอบ 403 InvalidProviderToken
 * โดยไม่บอกว่าเพราะอะไร และไม่มีอะไรบนหน้าจอบอกเราเลยว่าการ์ดไม่ขยับเพราะอะไร
 */
class ApnsLiveActivityTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        openssl_pkey_export($key, $exported);
        $this->privateKey = $exported;

        config([
            'services.apns.key_id' => 'KEY123',
            'services.apns.team_id' => 'TEAM456',
            'services.apns.bundle_id' => 'com.luilaykhao.app',
            'services.apns.key_content' => $this->privateKey,
            'services.apns.production' => true,
        ]);
    }

    public function test_it_is_disabled_without_a_key(): void
    {
        config(['services.apns.key_id' => null]);

        $this->assertFalse(app(ApnsLiveActivityService::class)->isConfigured());
    }

    public function test_update_signs_a_verifiable_es256_token_and_targets_the_live_activity_topic(): void
    {
        Http::fake(['api.push.apple.com/*' => Http::response('', 200)]);

        app(ApnsLiveActivityService::class)->update($this->activity(), [
            'stage' => 'approaching',
            'headline' => 'รถถึงใน 8 นาที',
        ]);

        Http::assertSent(function ($request) {
            $this->assertSame(
                'com.luilaykhao.app.push-type.liveactivity',
                $request->header('apns-topic')[0],
            );
            $this->assertSame('liveactivity', $request->header('apns-push-type')[0]);
            $this->assertSame('update', $request['aps']['event']);
            $this->assertSame('รถถึงใน 8 นาที', $request['aps']['content-state']['headline']);

            $jwt = str_replace('bearer ', '', $request->header('authorization')[0]);
            $this->assertJwtIsValid($jwt);

            return true;
        });
    }

    public function test_alerting_updates_are_sent_at_the_highest_priority(): void
    {
        Http::fake(['api.push.apple.com/*' => Http::response('', 200)]);

        // "รถถึงจุดรับแล้ว" ต้องแทรกคิวได้ทันที ไม่ใช่ถูกระบบหน่วงไว้เหมือนการ
        // อัปเดตตัวเลขเฉย ๆ — คนที่รออยู่จะพลาดรถ
        app(ApnsLiveActivityService::class)->update(
            $this->activity(),
            ['stage' => 'arrived'],
            ['title' => 'รถถึงจุดรับแล้ว', 'body' => 'ขึ้นรถได้เลย'],
        );

        Http::assertSent(fn ($request) => $request->header('apns-priority')[0] === '10');
    }

    public function test_a_dead_token_closes_the_activity_instead_of_being_retried_forever(): void
    {
        Http::fake([
            'api.push.apple.com/*' => Http::response(['reason' => 'BadDeviceToken'], 410),
        ]);

        $activity = $this->activity();
        app(ApnsLiveActivityService::class)->update($activity, ['stage' => 'countdown']);

        $this->assertNotNull($activity->fresh()->ended_at);
    }

    public function test_ending_sets_a_dismissal_date_so_the_last_line_stays_briefly(): void
    {
        Http::fake(['api.push.apple.com/*' => Http::response('', 200)]);

        $activity = $this->activity();
        app(ApnsLiveActivityService::class)->end($activity, ['stage' => 'ended']);

        Http::assertSent(function ($request) {
            $this->assertSame('end', $request['aps']['event']);
            $this->assertNotNull($request['aps']['dismissal-date']);

            return true;
        });

        $this->assertNotNull($activity->fresh()->ended_at);
    }

    private function assertJwtIsValid(string $jwt): void
    {
        [$header64, $claims64, $signature64] = explode('.', $jwt);

        $header = json_decode($this->base64UrlDecode($header64), true);
        $this->assertSame('ES256', $header['alg']);
        $this->assertSame('KEY123', $header['kid']);

        $claims = json_decode($this->base64UrlDecode($claims64), true);
        $this->assertSame('TEAM456', $claims['iss']);

        $signature = $this->base64UrlDecode($signature64);
        // ES256 = r และ s ฝั่งละ 32 ไบต์พอดี ไม่ใช่ DER ที่ยาวไม่คงที่
        $this->assertSame(64, strlen($signature));

        $publicKey = openssl_pkey_get_details(openssl_pkey_get_private($this->privateKey))['key'];
        $this->assertSame(
            1,
            openssl_verify(
                "{$header64}.{$claims64}",
                $this->joseToDer($signature),
                $publicKey,
                OPENSSL_ALGO_SHA256,
            ),
        );
    }

    /** แปลง r‖s กลับเป็น DER เพื่อให้ openssl_verify ตรวจได้ */
    private function joseToDer(string $signature): string
    {
        $encodeInteger = function (string $value): string {
            $value = ltrim($value, "\x00");
            if (ord($value[0]) > 0x7F) {
                $value = "\x00".$value;
            }

            return "\x02".chr(strlen($value)).$value;
        };

        $body = $encodeInteger(substr($signature, 0, 32)).$encodeInteger(substr($signature, 32));

        return "\x30".chr(strlen($body)).$body;
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }

    private function activity(): LiveActivity
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง', 'slug' => 'apns-'.uniqid(), 'type' => 'trekking',
            'location' => 'เลย', 'difficulty' => 'medium', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 2500, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 1, 'status' => 'open',
            'transport_type' => 'van',
        ]);

        $user = User::factory()->create();

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 2500,
            'paid_amount' => 2500,
        ]);

        return LiveActivity::create([
            'user_id' => $user->id,
            'booking_id' => $booking->id,
            'schedule_id' => $schedule->id,
            'platform' => 'ios',
            'push_token' => 'device-token-'.uniqid(),
            'started_at' => now(),
        ]);
    }
}
