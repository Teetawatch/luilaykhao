<?php

namespace App\Http\Controllers;

use App\Services\TripBriefService;
use Illuminate\Http\Response;

/**
 * "ใบเดินทาง" สาธารณะ /t/{token} — กำหนดการ จุดขึ้นรถ รถ และเบอร์ทีมงานของรอบ
 * รวมอยู่หน้าเดียว เปิดจากลิงก์ในอีเมลหรือ SMS ได้เลย ไม่ต้องล็อกอิน ไม่ต้องมีแอป
 *
 * server-rendered ทั้งหน้าโดยตั้งใจ: ลูกค้ากลุ่มที่ต้องใช้หน้านี้มากที่สุดคือคนที่
 * เปิดมันตอนยืนรออยู่ริมถนนตีสี่ ด้วยสัญญาณเส้นเดียว — หน้าเดียวที่มาครบในครั้งเดียว
 * ชนะ SPA ที่ต้องโหลด JS ก่อนเสมอ
 */
class PublicTripBriefController extends Controller
{
    public function __construct(private TripBriefService $briefs) {}

    public function show(string $token): Response
    {
        $brief = $this->briefs->forToken($token);

        if ($brief === null) {
            return response()->view('trip-brief', ['brief' => null], 404);
        }

        return response()->view('trip-brief', ['brief' => $brief]);
    }
}
