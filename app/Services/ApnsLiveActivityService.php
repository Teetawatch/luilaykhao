<?php

namespace App\Services;

use App\Models\LiveActivity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ยิงอัปเดต Live Activity เข้า APNs โดยตรง
 *
 * Live Activity เป็น push ประเภทเดียวที่ FCM ส่งให้ไม่ได้: มันต้องไปที่ token ของ
 * ตัว Activity เอง บน topic `<bundle>.push-type.liveactivity` และ payload ต้องมี
 * `content-state` ที่ตรงกับ struct ฝั่ง Swift เป๊ะ ๆ คลาสนี้จึงคุยกับ APNs ตรงด้วย
 * auth key (.p8) — เซ็น JWT แบบ ES256 เอง เพราะโปรเจ็กต์นี้ไม่มีไลบรารี JWT
 *
 * ทุกทางที่ล้มเหลวถูกกลืนเป็น log — ไม่มีกรณีไหนที่ Live Activity ส่งไม่ได้แล้วควร
 * ทำให้คำขอของลูกค้าพัง มันคือของประดับที่ดีมาก ไม่ใช่ระบบที่ธุรกิจแขวนอยู่
 */
class ApnsLiveActivityService
{
    /** APNs ตัดการเชื่อมต่อถ้า JWT เก่ากว่า 1 ชม. และปฏิเสธถ้าใหม่กว่า 20 นาทีต่อครั้ง */
    private const TOKEN_TTL_MINUTES = 50;

    private const PRODUCTION_HOST = 'https://api.push.apple.com';

    private const SANDBOX_HOST = 'https://api.sandbox.push.apple.com';

    /**
     * ตั้งค่าครบพอที่จะยิงได้หรือยัง — ที่อื่นเรียกเช็คก่อนเสมอ เพื่อไม่ให้เครื่อง
     * dev ที่ไม่มีคีย์เขียน log รัว ๆ ทุกนาที
     */
    public function isConfigured(): bool
    {
        return filled(config('services.apns.key_id'))
            && filled(config('services.apns.team_id'))
            && $this->privateKey() !== null;
    }

    /**
     * อัปเดต Activity ที่ยัง live อยู่
     *
     * @param  array<string, mixed>  $contentState  ต้องตรงกับ TripActivityAttributes.ContentState ฝั่ง Swift
     * @param  array{title: string, body: string}|null  $alert  ใส่เมื่ออยากให้เครื่องสั่น/เด้ง (เช่น "รถถึงจุดรับแล้ว")
     */
    public function update(LiveActivity $activity, array $contentState, ?array $alert = null, ?int $staleAfterMinutes = 30): bool
    {
        return $this->send($activity, [
            'event' => 'update',
            'content-state' => $contentState,
            'stale-date' => $staleAfterMinutes ? now()->addMinutes($staleAfterMinutes)->timestamp : null,
            'alert' => $alert,
        ], priority: $alert ? 10 : 5);
    }

    /**
     * ปิด Activity — ค้างบนหน้าจอล็อกได้อีก [dismissAfterMinutes] นาทีให้คนที่เพิ่ง
     * ลงรถได้เห็นบรรทัดสุดท้าย แล้วหายไปเอง
     */
    public function end(LiveActivity $activity, array $contentState, int $dismissAfterMinutes = 5): bool
    {
        $sent = $this->send($activity, [
            'event' => 'end',
            'content-state' => $contentState,
            'dismissal-date' => now()->addMinutes($dismissAfterMinutes)->timestamp,
        ], priority: 10);

        $activity->forceFill(['ended_at' => now()])->save();

        return $sent;
    }

    /**
     * เริ่ม Activity จากฝั่งเซิร์ฟเวอร์ (push-to-start, iOS 17.2+)
     *
     * นี่คือสิ่งที่ทำให้ "เช้าวันเดินทางแล้วมันขึ้นมาเอง" เป็นจริงโดยลูกค้าไม่ต้อง
     * เปิดแอปเลย — [$startToken] คือ pushToStartToken ของแอปทั้งแอป (ไม่ใช่ของ
     * Activity ใดตัวหนึ่ง) ซึ่งแอปส่งมาเก็บไว้ตั้งแต่ติดตั้ง
     *
     * @param  array<string, mixed>  $attributes  ค่าคงที่ตลอดอายุ Activity (ชื่อทริป/จุดรับ)
     */
    public function start(string $startToken, array $attributes, array $contentState, ?array $alert = null): bool
    {
        return $this->post($startToken, [
            'timestamp' => now()->timestamp,
            'event' => 'start',
            'attributes-type' => 'TripActivityAttributes',
            'attributes' => $attributes,
            'content-state' => $contentState,
            'alert' => $alert,
            'stale-date' => now()->addMinutes(60)->timestamp,
        ], priority: 10);
    }

    /**
     * ตรวจว่าคีย์/Team ID/topic ที่ตั้งไว้ใช้กับ APNs ได้จริง — โดยไม่ต้องมีเครื่องจริง
     *
     * ยิงไปหา device token ปลอมแล้วอ่านเหตุผลที่ Apple ปฏิเสธ ซึ่งแยกสองอย่างที่
     * ต่างกันมากออกจากกันได้: "ปฏิเสธเพราะ token ปลอม" (= ตัวตนเราผ่านแล้ว) กับ
     * "ปฏิเสธเพราะไม่รู้จักเรา" (= คีย์/ทีม/บันเดิลผิด) เดิมต้องรอเอา iPhone มาลอง
     * ถึงจะรู้ ซึ่งช้าเกินไปสำหรับความผิดพลาดที่แก้ได้ในสามวินาที
     *
     * @return array{ok: bool, status: int|null, reason: string, message: string}
     */
    public function verifyCredentials(): array
    {
        if (! $this->isConfigured()) {
            return [
                'ok' => false,
                'status' => null,
                'reason' => 'NotConfigured',
                'message' => 'ยังไม่ได้ตั้ง APNS_KEY_ID / APNS_TEAM_ID หรือหาไฟล์ .p8 ไม่เจอ',
            ];
        }

        $jwt = $this->authToken();
        if ($jwt === null) {
            return [
                'ok' => false,
                'status' => null,
                'reason' => 'BadKeyFile',
                'message' => 'ไฟล์ .p8 อ่านไม่ออก — ต้องเป็นคีย์ EC P-256 จาก Apple Developer > Keys',
            ];
        }

        $bundleId = (string) config('services.apns.bundle_id');
        $host = config('services.apns.production') ? self::PRODUCTION_HOST : self::SANDBOX_HOST;
        // token ปลอมที่หน้าตาถูกต้อง (hex 64 ตัว) — APNs จะตรวจตัวตนเราก่อนตรวจ token
        $fakeToken = str_repeat('0', 64);

        try {
            $response = Http::withHeaders([
                'authorization' => "bearer {$jwt}",
                'apns-topic' => "{$bundleId}.push-type.liveactivity",
                'apns-push-type' => 'liveactivity',
                'apns-priority' => '5',
            ])
                ->withOptions(['version' => 2.0])
                ->timeout(10)
                ->post("{$host}/3/device/{$fakeToken}", [
                    'aps' => ['timestamp' => now()->timestamp, 'event' => 'update', 'content-state' => []],
                ]);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => null,
                'reason' => 'ConnectionFailed',
                'message' => 'ต่อ APNs ไม่ได้: '.$e->getMessage(),
            ];
        }

        $reason = (string) $response->json('reason', '');

        // BadDeviceToken = ผ่านด่านตัวตนแล้ว เหลือแค่ token ที่เราตั้งใจส่งปลอมไป
        $ok = in_array($reason, ['BadDeviceToken', 'DeviceTokenNotForTopic', 'Unregistered'], true);

        return [
            'ok' => $ok,
            'status' => $response->status(),
            'reason' => $reason ?: 'OK',
            'message' => match (true) {
                $ok => 'คีย์ ทีม และ topic ถูกต้อง — พร้อมใช้งาน',
                $reason === 'InvalidProviderToken' => 'APNS_KEY_ID หรือ APNS_TEAM_ID ไม่ตรงกับไฟล์ .p8',
                $reason === 'ExpiredProviderToken' => 'นาฬิกาเครื่องเพี้ยนจนโทเคนหมดอายุตั้งแต่เกิด',
                $reason === 'TopicDisallowed' => 'APNS_BUNDLE_ID ไม่ใช่บันเดิลของแอปนี้ หรือคีย์ไม่มีสิทธิ์ APNs',
                $reason === 'Forbidden' => 'คีย์ถูก revoke ไปแล้ว',
                // คีย์ใบนี้ผูกกับสภาพแวดล้อมเดียว — สลับ APNS_PRODUCTION แล้วลองใหม่
                $reason === 'BadEnvironmentKeyInToken' => 'คีย์ใบนี้ใช้กับ '.($host === self::PRODUCTION_HOST ? 'production' : 'sandbox').' ไม่ได้ — ลองสลับ APNS_PRODUCTION',
                default => 'APNs ตอบกลับด้วยเหตุผลที่ไม่คาดคิด',
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $aps  ส่วนที่ต่างกันของ payload (ไม่รวม timestamp)
     */
    private function send(LiveActivity $activity, array $aps, int $priority): bool
    {
        $ok = $this->post($activity->push_token, [
            'timestamp' => now()->timestamp,
            ...$aps,
        ], $priority, onDeadToken: function () use ($activity) {
            // เครื่องปิด Activity ไปแล้ว / ถอนแอป — token นี้ตายถาวร
            $activity->forceFill(['ended_at' => now()])->save();
        });

        if ($ok) {
            $activity->forceFill(['last_pushed_at' => now()])->save();
        }

        return $ok;
    }

    private function post(string $deviceToken, array $aps, int $priority, ?callable $onDeadToken = null): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $jwt = $this->authToken();
        if ($jwt === null) {
            return false;
        }

        $bundleId = (string) config('services.apns.bundle_id');
        $host = config('services.apns.production') ? self::PRODUCTION_HOST : self::SANDBOX_HOST;

        try {
            $response = Http::withHeaders([
                'authorization' => "bearer {$jwt}",
                'apns-topic' => "{$bundleId}.push-type.liveactivity",
                'apns-push-type' => 'liveactivity',
                'apns-priority' => (string) $priority,
                'apns-expiration' => (string) now()->addMinutes(10)->timestamp,
            ])
                // APNs รับเฉพาะ HTTP/2 — ถ้า curl ในเครื่องไม่รองรับ คำขอจะถูกปฏิเสธ
                // ที่ปลายทาง ไม่ใช่เงียบหาย
                ->withOptions(['version' => 2.0])
                ->timeout(10)
                ->post("{$host}/3/device/{$deviceToken}", [
                    'aps' => array_filter($aps, fn ($value) => $value !== null),
                ]);

            if ($response->successful()) {
                return true;
            }

            $reason = (string) $response->json('reason', '');
            if (in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic', 'ExpiredToken'], true)) {
                if ($onDeadToken !== null) {
                    $onDeadToken();
                }

                return false;
            }

            Log::warning('APNs live activity push failed', [
                'status' => $response->status(),
                'reason' => $reason ?: $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('APNs live activity push exception', ['message' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * JWT provider token ที่ APNs ใช้แทน client certificate — แชร์ได้ทุกคำขอ
     * ภายในอายุของมัน จึงแคชไว้แทนที่จะเซ็นใหม่ทุกนาที
     */
    private function authToken(): ?string
    {
        return Cache::remember('apns_live_activity_jwt', now()->addMinutes(self::TOKEN_TTL_MINUTES), function () {
            $key = $this->privateKey();
            if ($key === null) {
                return null;
            }

            $header = $this->base64UrlEncode(json_encode([
                'alg' => 'ES256',
                'kid' => (string) config('services.apns.key_id'),
            ], JSON_THROW_ON_ERROR));

            $claims = $this->base64UrlEncode(json_encode([
                'iss' => (string) config('services.apns.team_id'),
                'iat' => time(),
            ], JSON_THROW_ON_ERROR));

            $unsigned = "{$header}.{$claims}";

            $pkey = openssl_pkey_get_private($key);
            if ($pkey === false) {
                Log::warning('APNs auth key could not be parsed — check APNS_KEY_PATH/APNS_KEY_CONTENT');

                return null;
            }

            if (! openssl_sign($unsigned, $der, $pkey, OPENSSL_ALGO_SHA256)) {
                return null;
            }

            $signature = $this->derToJose($der);
            if ($signature === null) {
                return null;
            }

            return "{$unsigned}.{$this->base64UrlEncode($signature)}";
        });
    }

    /**
     * openssl เซ็น ECDSA ออกมาเป็น DER (SEQUENCE{INTEGER r, INTEGER s}) แต่ JWS
     * ES256 ต้องการ r‖s ดิบ ๆ ฝั่งละ 32 ไบต์ ถ้าไม่แปลง APNs จะตอบ 403
     * InvalidProviderToken โดยไม่บอกว่าเพราะอะไร
     */
    private function derToJose(string $der): ?string
    {
        $offset = 0;
        if (($der[$offset] ?? '') !== "\x30") {
            return null;
        }
        $offset++;

        // ความยาวของ SEQUENCE — ข้ามไป ไม่ได้ใช้ (รูปแบบ long-form ถ้าบิตสูงติด)
        $lengthByte = ord($der[$offset] ?? "\x00");
        $offset++;
        if ($lengthByte > 0x80) {
            $offset += $lengthByte - 0x80;
        }

        $readInteger = function () use ($der, &$offset): ?string {
            if (($der[$offset] ?? '') !== "\x02") {
                return null;
            }
            $offset++;
            $length = ord($der[$offset] ?? "\x00");
            $offset++;
            $value = substr($der, $offset, $length);
            $offset += $length;

            // DER เติม 0x00 นำหน้าเมื่อบิตสูงติด (กันตีความเป็นเลขลบ) — ตัดทิ้ง
            $value = ltrim($value, "\x00");

            return str_pad($value, 32, "\x00", STR_PAD_LEFT);
        };

        $r = $readInteger();
        $s = $readInteger();

        return ($r === null || $s === null) ? null : $r.$s;
    }

    /**
     * เนื้อไฟล์ .p8 — จาก env โดยตรง (เครื่องที่ deploy ด้วย secret manager) หรือ
     * จากไฟล์บนดิสก์
     */
    private function privateKey(): ?string
    {
        $inline = config('services.apns.key_content');
        if (filled($inline)) {
            return str_replace('\n', "\n", (string) $inline);
        }

        $path = config('services.apns.key_path');
        if (blank($path)) {
            return null;
        }

        if (! Str::contains($path, [':\\', ':/']) && ! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = base_path($path);
        }

        return is_file($path) ? file_get_contents($path) : null;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
