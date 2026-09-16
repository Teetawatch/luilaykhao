<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\LegalPolicy;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * เงื่อนไขที่ประกาศใช้อยู่ สำหรับไคลเอนต์ที่ import config ไม่ได้
 *
 * หน้าเว็บอ่านตัวเลขชุดนี้จาก resources/js/lib/policy.js ตอน build แต่ LIFF
 * เป็นไฟล์ static ที่ไม่มี build step — ถ้าไม่มี endpoint นี้ มันต้องพิมพ์
 * เงื่อนไขซ้ำไว้ในตัวเอง แล้วเราก็จะกลับไปมีเงื่อนไขที่ขัดกันเองอีกครั้ง
 */
class LegalController extends Controller
{
    use ApiResponse;

    public function policy(): JsonResponse
    {
        return $this->success(LegalPolicy::payload());
    }
}
