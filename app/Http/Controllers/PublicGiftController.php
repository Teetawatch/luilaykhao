<?php

namespace App\Http\Controllers;

use App\Services\GiftService;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * หน้าเว็บ "เปิดของขวัญ" สาธารณะ เข้าผ่านลิงก์ /gift/{code} ที่ผู้ให้ส่งให้ผู้รับ
 * ไม่ต้องล็อกอิน — โชว์ทริป ผู้ให้ และคำอวยพร (ไม่มีราคา) พร้อมปุ่มเปิดในแอป
 * เพื่อกดรับ (การกดรับจริงต้องล็อกอินในแอป)
 */
class PublicGiftController extends Controller
{
    public function __construct(private GiftService $giftService) {}

    public function show(string $code): View|Response
    {
        $gift = $this->giftService->publicView($code);

        if ($gift === null) {
            return response()->view('gift', ['gift' => null, 'code' => $code], 404);
        }

        return response()->view('gift', [
            'gift' => $gift,
            'code' => $gift['gift_code'],
        ]);
    }
}
