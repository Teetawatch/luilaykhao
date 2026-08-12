<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\SiteSettings;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ตั้งค่าระบบทั่วไป — ตัวเลขที่เคยฝังไว้ในโค้ด
 *
 * เกณฑ์การันตีออกเดินทาง เกณฑ์ที่นั่งใกล้เต็ม ช่วงเวลางดยิง push และข้อมูลติดต่อ
 * เคยเป็น const ที่ต้อง deploy ใหม่ทุกครั้งที่อยากขยับ ตอนนี้อ่านผ่าน
 * [SiteSettings] ซึ่งมีค่าตั้งต้นเท่าเดิม — เว้นว่างไว้ = ใช้ค่าเดิมทุกประการ
 */
class AdminSettingsController extends Controller
{
    use ApiResponse;

    public function show(): JsonResponse
    {
        return $this->success([
            'settings' => SiteSettings::all(),
            'defaults' => SiteSettings::DEFAULTS,
            // URL ที่ใช้จริงตอนนี้ — ต่างจาก settings.licence_image ตรงที่เผื่อ
            // กรณียังไม่เคยอัปโหลด แล้วระบบถอยไปใช้ไฟล์เดิม แอดมินจะได้เห็นว่า
            // ลูกค้ากำลังเห็นรูปไหนอยู่ ไม่ใช่ช่องว่าง
            'licence_image_url' => SiteSettings::licenceImageUrl(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'guarantee_min_seats' => ['required', 'integer', 'min:1', 'max:50'],
            'low_seat_threshold' => ['required', 'integer', 'min:1', 'max:20'],
            'underfilled_min_seats' => ['required', 'integer', 'min:1', 'max:50'],
            'quiet_hours_enabled' => ['required', 'boolean'],
            'quiet_start_hour' => ['required', 'integer', 'min:0', 'max:23'],
            'quiet_end_hour' => ['required', 'integer', 'min:0', 'max:23'],
            'support_phone' => ['nullable', 'string', 'max:40'],
            'support_line' => ['nullable', 'string', 'max:80'],
            'support_email' => ['nullable', 'email', 'max:120'],
            // เลขที่ใบอนุญาตเป็นเอกสารราชการ รูปแบบคือ เลขกลุ่ม/เลขลำดับ
            'licence_no' => ['required', 'string', 'regex:/^[0-9]{1,3}\/[0-9]{3,8}$/'],
            'licence_image' => ['nullable', 'string', 'max:2048'],
        ]);

        if ($data['quiet_start_hour'] === $data['quiet_end_hour']) {
            return $this->error('ช่วงเวลางดรบกวนต้องไม่เริ่มและจบชั่วโมงเดียวกัน', 422);
        }

        Setting::put(SiteSettings::KEY, $data);

        return $this->success([
            ...SiteSettings::all(),
            'licence_image_url' => SiteSettings::licenceImageUrl(),
        ], 'บันทึกการตั้งค่าระบบแล้ว');
    }
}
