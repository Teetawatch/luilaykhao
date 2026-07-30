<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\PageContent;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * เนื้อหาหน้า "ข้อมูลก่อนไป" สำหรับฝั่งผู้ใช้ — อ่านอย่างเดียว ไม่ต้องล็อกอิน
 */
class PageContentController extends Controller
{
    use ApiResponse;

    public function show(string $key): JsonResponse
    {
        if (! PageContent::has($key)) {
            return $this->error('ไม่พบหน้าเนื้อหานี้', 404);
        }

        return $this->success(PageContent::get($key));
    }
}
