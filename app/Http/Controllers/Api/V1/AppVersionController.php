<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Exposes the minimum supported mobile app version so the Flutter app can
 * force users onto the store when an older build is still installed.
 *
 * Tweak `MIN_MOBILE_VERSION` in .env to raise the floor without re-deploying.
 */
class AppVersionController extends Controller
{
    use ApiResponse;

    public function show(): JsonResponse
    {
        $storeUrl = config(
            'app.mobile_store_url',
            'https://play.google.com/store/apps/details?id=com.luilaykhao.app',
        );

        return $this->success([
            'min_version' => config('app.min_mobile_version', '0.1.0'),
            'latest_version' => config('app.latest_mobile_version', '0.1.0'),
            // Generic fallback kept for older builds that only read `store_url`.
            'store_url' => $storeUrl,
            // Platform-specific links so the app can send iOS users to the App
            // Store and Android users to the Play Store.
            'ios_store_url' => config('app.mobile_ios_store_url') ?: $storeUrl,
            'android_store_url' => config('app.mobile_android_store_url') ?: $storeUrl,
            'message' => config(
                'app.mobile_update_message',
                'อัปเดตเพื่อใช้ฟีเจอร์ใหม่และแก้ไขบั๊กล่าสุด',
            ),
        ]);
    }
}
