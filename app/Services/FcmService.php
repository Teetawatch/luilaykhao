<?php

namespace App\Services;

use App\Models\FcmToken;
use App\Models\SmartNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FcmService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const SOS_TYPE = 'sos_alert';

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
                [
                    'notification_id' => (string) $notification->id,
                    'type' => $notification->type,
                    ...$this->stringData($notification->data ?? []),
                ],
                $notification->type,
            );
        }
    }

    public function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        $tokens = FcmToken::where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('token');

        foreach ($tokens as $token) {
            $this->sendToToken($token, $title, $body, $data, $data['type'] ?? null);
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
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
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

    private function sendToToken(string $token, string $title, string $body, array $data = [], ?string $type = null): void
    {
        $projectId = config('services.fcm.project_id');
        if (! $projectId) {
            return;
        }

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
            $messagePayload = [
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
        } else {
            $messagePayload = array_filter([
                'token' => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => $this->stringData($data),
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => [
                        'channel_id' => 'important_updates',
                        'sound' => 'default',
                        'notification_priority' => 'PRIORITY_HIGH',
                        'default_vibrate_timings' => true,
                        'visibility' => 'PUBLIC',
                    ],
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                        'apns-push-type' => 'alert',
                    ],
                    'payload' => [
                        'aps' => [
                            'alert' => ['title' => $title, 'body' => $body],
                            'sound' => 'default',
                            'badge' => 1,
                            'content-available' => 1,
                            'interruption-level' => 'active',
                        ],
                    ],
                ],
            ]);
        }

        try {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => $messagePayload,
                ]);

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
        } catch (\Throwable $e) {
            Log::warning('FCM send exception', ['message' => $e->getMessage()]);
        }
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
