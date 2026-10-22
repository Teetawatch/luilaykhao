<?php

namespace App\Services;

use App\Models\FcmToken;
use App\Models\SmartNotification;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FcmService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const SOS_TYPE = 'sos_alert';

    /** Seconds. Without these a stalled FCM call waits Laravel's default 30 s. */
    private const CONNECT_TIMEOUT = 5;

    private const REQUEST_TIMEOUT = 10;

    /** Requests in flight at once when pushing a batch — see [sendNotifications]. */
    public const POOL_SIZE = 20;

    /**
     * channel ของคำขอจากลูกทริปที่ต้องปลุกทีมงาน (ห้องน้ำ ขอยา ขาดคนตอนออกรถ …)
     * สั่นยาวกว่าแจ้งเตือนปกติ — ต้องตรงกับ `PushNotificationService.staffAlertChannel`
     * ฝั่งแอป ซึ่งเป็นคนกำหนดรูปแบบการสั่นจริง (Android 8+ เอาจาก channel)
     */
    public const STAFF_ALERT_CHANNEL = 'staff_alerts';

    /** ใช้กับ Android รุ่นก่อน 8 ที่ยังไม่มี channel — รูปแบบเดียวกับฝั่งแอป */
    private const STAFF_ALERT_VIBRATION = ['0s', '0.8s', '0.3s', '0.8s', '0.3s', '0.8s'];

    public function sendNotification(SmartNotification $notification): void
    {
        $tokens = FcmToken::where('user_id', $notification->user_id)
            ->where('is_active', true)
            ->pluck('token');

        foreach ($tokens as $token) {
            $this->sendToToken(
                $token,
                $notification->title,
                $notification->body,
                $this->notificationData($notification),
                $notification->type,
            );
        }
    }

    /**
     * Push many inbox notifications at once — the broadcast path.
     *
     * One request at a time, each FCM call opens a fresh TLS connection and
     * costs roughly half a second from our server, so a 50-user broadcast slice
     * with a device or two each ran past Horizon's 60 s timeout and was killed
     * partway (2026-10-12/13). Here the requests go out [POOL_SIZE] at a time.
     *
     * @param  iterable<SmartNotification>  $notifications
     */
    public function sendNotifications(iterable $notifications): void
    {
        $projectId = config('services.fcm.project_id');
        if (! $projectId) {
            return;
        }

        $notifications = collect($notifications);
        $tokensByUser = FcmToken::whereIn('user_id', $notifications->pluck('user_id')->unique()->all())
            ->where('is_active', true)
            ->get(['user_id', 'token'])
            ->groupBy('user_id');

        $messages = [];
        foreach ($notifications as $notification) {
            foreach ($tokensByUser->get($notification->user_id, []) as $fcmToken) {
                $messages[] = [
                    'token' => $fcmToken->token,
                    'payload' => $this->messagePayload(
                        $fcmToken->token,
                        $notification->title,
                        $notification->body,
                        $this->notificationData($notification),
                        $notification->type,
                    ),
                ];
            }
        }

        if ($messages === []) {
            return;
        }

        try {
            $accessToken = $this->accessToken();
        } catch (\Throwable $e) {
            Log::warning('FCM send exception', ['message' => $e->getMessage()]);

            return;
        }

        foreach (array_chunk($messages, self::POOL_SIZE) as $batch) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (array $message) => $pool->withToken($accessToken)
                    ->acceptJson()
                    ->connectTimeout(self::CONNECT_TIMEOUT)
                    ->timeout(self::REQUEST_TIMEOUT)
                    ->post($this->sendUrl($projectId), ['message' => $message['payload']]),
                $batch,
            ));

            foreach ($batch as $i => $message) {
                $response = $responses[$i] ?? null;
                if ($response instanceof Response) {
                    $this->recordResult($message['token'], $response);
                } else {
                    Log::warning('FCM send exception', [
                        'message' => $response instanceof \Throwable ? $response->getMessage() : 'no response',
                    ]);
                }
            }
        }
    }

    private function notificationData(SmartNotification $notification): array
    {
        return [
            'notification_id' => (string) $notification->id,
            'type' => $notification->type,
            ...$this->stringData($notification->data ?? []),
        ];
    }

    /**
     * $display ปรับวิธีแสดงผล (ไม่ส่ง = แจ้งเตือนปกติแบบเดิม):
     * - tag: ใบที่ tag ตรงกันทับใบเดิมในถาดแทนการซ้อนเพิ่ม (Android tag / iOS apns-collapse-id)
     * - thread: กลุ่มแจ้งเตือนบน iOS (thread-id) — ไม่ส่ง = ใช้ tag
     * - quiet: ขึ้นในถาดเงียบ ๆ ไม่มีเสียง ไม่ปลุกหน้าจอ
     * - android_channel: channel ที่แอปสร้างไว้ (เครื่องที่ยังไม่มี channel นี้ Android
     *   จะใช้ channel เริ่มต้นใน manifest แทน)
     * - staff_alert: ลูกทริปเรียกทีมงาน — สั่นยาวบน channel STAFF_ALERT_CHANNEL และ iOS
     *   ส่งเป็น time-sensitive ให้ทะลุโหมดนอน/โฟกัส (ทับ quiet และ android_channel)
     *
     * @param  array{tag?: string, thread?: string, quiet?: bool, android_channel?: string, staff_alert?: bool}  $display
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = [], array $display = []): void
    {
        $tokens = FcmToken::where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('token');

        foreach ($tokens as $token) {
            $this->sendToToken($token, $title, $body, $data, $data['type'] ?? null, $display);
        }
    }

    /**
     * ส่ง data message ล้วน ๆ (ไม่มีแถบแจ้งเตือน) ให้ทุกเครื่องของผู้ใช้
     *
     * ใช้กับข้อมูลที่แอปต้องเอาไป "วาด" เอง ไม่ใช่ข้อความที่ต้องอ่าน — ตอนนี้คือ
     * การ์ดวันเดินทางบนแถบแจ้งเตือนของ Android (ดู [TripActivityService]) ซึ่งถ้า
     * ส่งเป็น notification ปกติ ระบบจะเด้งการ์ดใหม่ทุกนาทีแทนที่จะอัปเดตใบเดิม
     */
    public function sendDataToUser(int $userId, array $data, ?string $platform = null): void
    {
        $tokens = FcmToken::where('user_id', $userId)
            ->where('is_active', true)
            ->when($platform, fn ($query) => $query->where('platform', $platform))
            ->pluck('token');

        foreach ($tokens as $token) {
            $this->sendDataToToken($token, $data);
        }
    }

    private function sendDataToToken(string $token, array $data): void
    {
        $projectId = config('services.fcm.project_id');
        if (! $projectId) {
            return;
        }

        try {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT)
                ->post($this->sendUrl($projectId), [
                    'message' => [
                        'token' => $token,
                        'data' => $this->stringData($data),
                        'android' => ['priority' => 'HIGH'],
                        'apns' => [
                            'headers' => ['apns-push-type' => 'background', 'apns-priority' => '5'],
                            'payload' => ['aps' => ['content-available' => 1]],
                        ],
                    ],
                ]);

            if (! $response->successful() && $this->isDeadToken($response->json())) {
                FcmToken::where('token', $token)->update(['is_active' => false]);
            }
        } catch (\Throwable $e) {
            Log::warning('FCM data send exception', ['message' => $e->getMessage()]);
        }
    }

    private function sendToToken(
        string $token,
        string $title,
        string $body,
        array $data = [],
        ?string $type = null,
        array $display = [],
    ): void {
        $projectId = config('services.fcm.project_id');
        if (! $projectId) {
            return;
        }

        try {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT)
                ->post($this->sendUrl($projectId), [
                    'message' => $this->messagePayload($token, $title, $body, $data, $type, $display),
                ]);

            $this->recordResult($token, $response);
        } catch (\Throwable $e) {
            Log::warning('FCM send exception', ['message' => $e->getMessage()]);
        }
    }

    private function messagePayload(
        string $token,
        string $title,
        string $body,
        array $data = [],
        ?string $type = null,
        array $display = [],
    ): array {
        $isSos = $type === self::SOS_TYPE;

        if ($isSos) {
            // Android SOS is sent DATA-ONLY (no `notification` block) so the
            // Flutter background handler builds the alert itself with
            // fullScreenIntent + the looping siren. A system-displayed FCM
            // notification cannot set fullScreenIntent/category=alarm, so on the
            // loud alarm-usage channel it posts silently (shows in the tray but
            // no sound/vibration until tapped) — which is exactly the bug this
            // replaces. iOS keeps its apns alert, which already sounds while
            // killed. The `title`/`body` ride along in `data` for the handler.
            return [
                'token' => $token,
                'data' => $this->stringData([
                    ...$data,
                    'title' => $title,
                    'body' => $body,
                ]),
                'android' => [
                    // High priority wakes the device from Doze and delivers
                    // immediately so the background handler can fire the siren.
                    'priority' => 'HIGH',
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                        'apns-push-type' => 'alert',
                    ],
                    'payload' => [
                        // No `content-available` so iOS treats it purely as a
                        // high-priority alert delivered immediately, instead of a
                        // background push the system may coalesce or delay.
                        'aps' => [
                            'alert' => ['title' => $title, 'body' => $body],
                            'sound' => 'sos_siren.wav',
                            'badge' => 1,
                            'interruption-level' => 'time-sensitive',
                        ],
                    ],
                ],
            ];
        }

        $tag = isset($display['tag']) && $display['tag'] !== '' ? (string) $display['tag'] : null;
        $staffAlert = (bool) ($display['staff_alert'] ?? false);
        $quiet = ! $staffAlert && (bool) ($display['quiet'] ?? false);
        if ($staffAlert) {
            // แอปที่เปิดอยู่อ่านค่านี้เพื่อสั่นเอง — ตอนอยู่หน้าจอ ระบบไม่สั่นให้ทุกกรณี
            $data['staff_alert'] = '1';
        }

        $androidNotification = [
            'channel_id' => $staffAlert ? self::STAFF_ALERT_CHANNEL : ($display['android_channel'] ?? 'important_updates'),
            'notification_priority' => match (true) {
                $staffAlert => 'PRIORITY_MAX',
                $quiet => 'PRIORITY_LOW',
                default => 'PRIORITY_HIGH',
            },
            'visibility' => 'PUBLIC',
        ];
        if ($staffAlert) {
            $androidNotification['sound'] = 'default';
            $androidNotification['vibrate_timings'] = self::STAFF_ALERT_VIBRATION;
        } elseif (! $quiet) {
            // Android 8+ เอาเสียง/การสั่นจาก channel อยู่แล้ว สองค่านี้มีผลแค่
            // เครื่องรุ่นเก่า — ใบเงียบจึงไม่ใส่
            $androidNotification['sound'] = 'default';
            $androidNotification['default_vibrate_timings'] = true;
        }
        if ($tag !== null) {
            $androidNotification['tag'] = $tag;
        }

        $apnsHeaders = [
            'apns-priority' => '10',
            'apns-push-type' => 'alert',
        ];
        $aps = [
            'alert' => ['title' => $title, 'body' => $body],
            'badge' => 1,
            'content-available' => 1,
            // passive = เข้าศูนย์แจ้งเตือนเงียบ ๆ ไม่ปลุกหน้าจอ ไม่มีเสียง
            // time-sensitive = ทะลุโหมดนอน/โฟกัส (entitlement มีอยู่แล้วจาก SOS)
            'interruption-level' => match (true) {
                $staffAlert => 'time-sensitive',
                $quiet => 'passive',
                default => 'active',
            },
        ];
        if (! $quiet) {
            $aps['sound'] = 'default';
        }
        if ($tag !== null) {
            // ใบใหม่ที่ collapse-id ตรงกันทับใบเดิมในศูนย์แจ้งเตือน (สูงสุด 64 ไบต์)
            $apnsHeaders['apns-collapse-id'] = substr($tag, 0, 64);
            $aps['thread-id'] = $display['thread'] ?? $tag;
        }

        return array_filter([
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => $this->stringData($data),
            'android' => [
                'priority' => 'HIGH',
                'notification' => $androidNotification,
            ],
            'apns' => [
                'headers' => $apnsHeaders,
                'payload' => [
                    'aps' => $aps,
                ],
            ],
        ]);
    }

    private function recordResult(string $token, Response $response): void
    {
        if ($response->successful()) {
            FcmToken::where('token', $token)->update(['last_used_at' => now()]);

            return;
        }

        if ($this->isDeadToken($response->json())) {
            FcmToken::where('token', $token)->update(['is_active' => false]);
        }

        Log::warning('FCM send failed', [
            'status' => $response->status(),
            'body' => $response->json() ?: $response->body(),
        ]);
    }

    private function sendUrl(string $projectId): string
    {
        return "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm_access_token', now()->addMinutes(50), function () {
            $serviceAccount = $this->serviceAccount();
            $now = time();

            $header = $this->base64UrlEncode(json_encode([
                'alg' => 'RS256',
                'typ' => 'JWT',
            ], JSON_THROW_ON_ERROR));

            $claims = $this->base64UrlEncode(json_encode([
                'iss' => $serviceAccount['client_email'],
                'scope' => self::SCOPE,
                'aud' => $serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));

            $unsignedJwt = "{$header}.{$claims}";
            openssl_sign($unsignedJwt, $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256);
            $assertion = "{$unsignedJwt}.{$this->base64UrlEncode($signature)}";

            $response = Http::asForm()->post($serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Unable to fetch FCM access token: '.$response->body());
            }

            return (string) $response->json('access_token');
        });
    }

    private function serviceAccount(): array
    {
        $path = config('services.fcm.service_account_path');
        if (! $path) {
            throw new \RuntimeException('FCM service account path is not configured.');
        }

        if (! Str::contains($path, [':\\', ':/']) && ! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = base_path($path);
        }

        if (! is_file($path)) {
            throw new \RuntimeException("FCM service account file not found: {$path}");
        }

        $serviceAccount = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach (['client_email', 'private_key'] as $key) {
            if (empty($serviceAccount[$key])) {
                throw new \RuntimeException("FCM service account is missing {$key}.");
            }
        }

        return $serviceAccount;
    }

    private function stringData(array $data): array
    {
        return collect($data)
            ->mapWithKeys(fn ($value, $key) => [(string) $key => is_scalar($value) ? (string) $value : json_encode($value)])
            ->all();
    }

    private function isDeadToken(mixed $body): bool
    {
        $encoded = json_encode($body);

        return is_string($encoded) && (
            str_contains($encoded, 'UNREGISTERED') ||
            str_contains($encoded, 'BadEnvironmentKeyInToken')
        );
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
