<?php

namespace App\Http\Controllers;

use App\Services\ProfileOgImageService;
use App\Services\PublicProfileService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * โปรไฟล์นักเดินทางสาธารณะ /u/{handle} — เสิร์ฟจากเซิร์ฟเวอร์ ไม่ใช่ SPA
 * เพราะบ็อตของ LINE/Facebook อ่าน meta tag จาก HTML ชุดแรกเท่านั้น
 * ถ้าปล่อยให้ Vue เรนเดอร์ทีหลัง การ์ดแชร์จะว่างเปล่า
 */
class PublicProfileController extends Controller
{
    /** อายุแคชของการ์ด OG — สถิติเปลี่ยนช้า ไม่ต้องวาดใหม่ทุกครั้งที่บ็อตมาดึง. */
    private const OG_CACHE_HOURS = 6;

    public function __construct(
        private PublicProfileService $profiles,
        private ProfileOgImageService $ogImages,
    ) {}

    public function show(string $handle): View|Response
    {
        $profile = $this->profiles->forHandle($handle);

        if ($profile === null) {
            return response()->view('traveler', ['profile' => null, 'handle' => $handle], 404);
        }

        return response()->view('traveler', [
            'profile' => $profile,
            'handle' => $profile['handle'],
        ]);
    }

    /**
     * การ์ด OG เป็น PNG. คืน 404 เมื่อโปรไฟล์ปิดอยู่ เพื่อไม่ให้รูปที่ค้างอยู่ตาม
     * ลิงก์เก่ายังเผยสถิติของคนที่เพิ่งปิดโปรไฟล์ไป
     */
    public function ogImage(string $handle): Response
    {
        $profile = $this->profiles->forHandle($handle);

        abort_if($profile === null, 404);

        $png = Cache::remember(
            'profile-og:'.$handle,
            now()->addHours(self::OG_CACHE_HOURS),
            fn () => $this->ogImages->render($profile),
        );

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age='.(self::OG_CACHE_HOURS * 3600),
        ]);
    }
}
