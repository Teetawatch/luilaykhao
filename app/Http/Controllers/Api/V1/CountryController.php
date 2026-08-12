<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Countries;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * ทะเบียนประเทศที่รองรับ — ให้ทุกหน้าจอ (เว็บ แอดมิน แอป) เห็นรายการเดียวกัน
 * แทนที่จะฮาร์ดโค้ดชื่อประเทศซ้ำในแต่ละที่แล้วหลุดกันภายหลัง
 */
class CountryController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        return $this->success(Countries::options());
    }
}
