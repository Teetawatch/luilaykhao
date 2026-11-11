<?php

namespace App\Http\Middleware;

use App\Support\ClientChannel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * งานเล็ก ๆ ที่ต้องรู้ว่า request มาจาก client ไหน — ทำหลัง $next เพราะ
 * auth:sanctum เป็น route middleware จึงรู้ตัวผู้ใช้ทีหลังกลุ่ม api (เหมือน SetAppLocale)
 *
 *  - token เว็บที่ยังถูกใช้ เลื่อนวันหมดอายุออกไป (ClientChannel::extendWebToken)
 *  - แอปที่ส่ง X-App-Version / X-Platform มา จำไว้ที่ users.app_version /
 *    app_platform / app_seen_at เพื่อรู้ว่าแอปรุ่นเก่ายังมีคนใช้แค่ไหน ก่อนจะเปลี่ยน
 *    รูปแบบ response (`php artisan app:versions`) เขียนเฉพาะตอนค่าเปลี่ยน หรือ
 *    ครั้งแรกของวัน
 *
 * งานพวกนี้พังได้โดยไม่กระทบคำตอบที่ลูกค้ารออยู่
 */
class TrackApiClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->track($request);
        } catch (\Throwable $e) {
            report($e);
        }

        return $response;
    }

    private function track(Request $request): void
    {
        $user = $request->user();
        if (! $user) {
            return;
        }

        ClientChannel::extendWebToken(ClientChannel::currentToken($request));

        $version = ClientChannel::appVersion($request);
        $platform = ClientChannel::platform($request);
        if (! $version || ! $platform) {
            return;
        }

        $fresh = $user->app_seen_at && $user->app_seen_at->isToday();
        if ($fresh && $user->app_version === $version && $user->app_platform === $platform) {
            return;
        }

        $user->forceFill([
            'app_version' => $version,
            'app_platform' => $platform,
            'app_seen_at' => now(),
        ])->saveQuietly();
    }
}
