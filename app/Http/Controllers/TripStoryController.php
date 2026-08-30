<?php

namespace App\Http\Controllers;

use App\Services\TripCountdownImageService;
use App\Services\TripStoryService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * หน้าการ์ดนับถอยหลังสาธารณะ /s/{token} — server-rendered เพราะบ็อตของ
 * LINE/Facebook อ่าน meta tag จาก HTML ชุดแรกเท่านั้น ถ้าปล่อยให้ Vue เรนเดอร์
 * ทีหลัง การ์ดพรีวิวตอนแชร์จะว่างเปล่า
 */
class TripStoryController extends Controller
{
    /**
     * อายุแคชของภาพ OG — สั้นกว่าการ์ดโปรไฟล์เพราะตัวเลขนับถอยหลังเปลี่ยนทุกวัน
     * หนึ่งชั่วโมงพอให้บ็อตหลายตัวมาดึงพร้อมกันโดยไม่วาดใหม่ทุกครั้ง และไม่นาน
     * จนตัวเลขค้างข้ามวัน
     */
    private const OG_CACHE_MINUTES = 60;

    public function __construct(
        private TripStoryService $stories,
        private TripCountdownImageService $images,
    ) {}

    public function show(string $token): View|Response
    {
        $card = $this->stories->forToken($token);

        if ($card === null) {
            return response()->view('story', ['card' => null, 'token' => $token], 404);
        }

        return response()->view('story', ['card' => $card, 'token' => $token]);
    }

    /**
     * ภาพ OG เป็น PNG. คืน 404 เมื่อการจองถูกยกเลิก เพื่อไม่ให้ลิงก์เก่ายังโชว์
     * ทริปที่เจ้าตัวไม่ได้ไปแล้ว
     */
    public function ogImage(string $token): Response
    {
        $card = $this->stories->forToken($token);

        abort_if($card === null, 404);

        // ใส่วันไว้ในคีย์ด้วย เพราะตัวเลข "อีก N วัน" เปลี่ยนทุกเที่ยงคืน — ถ้า
        // ล็อกไว้ที่โทเคนอย่างเดียว การ์ดจะค้างเลขของเมื่อวาน
        $key = 'trip-story-og:'.$token.':'.now('Asia/Bangkok')->toDateString();

        $png = Cache::remember(
            $key,
            now()->addMinutes(self::OG_CACHE_MINUTES),
            fn () => $this->images->render($card),
        );

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age='.(self::OG_CACHE_MINUTES * 60),
        ]);
    }
}
