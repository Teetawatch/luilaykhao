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
            // สั้นกว่า 5 นาทีลูกค้าแทบไม่ทันเปิดแอป ยาวเกิน 3 ชั่วโมงที่นั่งก็จมอยู่กับคนเดียว
            'waitlist_offer_ttl_minutes' => ['required', 'integer', 'min:5', 'max:180'],
            'quiet_hours_enabled' => ['required', 'boolean'],
            'quiet_start_hour' => ['required', 'integer', 'min:0', 'max:23'],
            'quiet_end_hour' => ['required', 'integer', 'min:0', 'max:23'],
            // ไม่ required เพราะ Setting::put เขียนทับทั้งก้อน — ฟอร์มรุ่นก่อนที่ยัง
            // ไม่มีช่องนี้จึงต้องบันทึกผ่านได้โดยไม่ปิดการยิง SMS ทิ้งไปเงียบ ๆ
            'sos_sms_enabled' => ['nullable', 'boolean'],
            'support_phone' => ['nullable', 'string', 'max:40'],
            'support_line' => ['nullable', 'string', 'max:80'],
            'support_email' => ['nullable', 'email', 'max:120'],
            // เลขที่ใบอนุญาตเป็นเอกสารราชการ รูปแบบคือ เลขกลุ่ม/เลขลำดับ
            'licence_no' => ['required', 'string', 'regex:/^[0-9]{1,3}\/[0-9]{3,8}$/'],
            'licence_image' => ['nullable', 'string', 'max:2048'],
            // บัญชีทริปโหมดเข้มงวด — nullable ทุกช่องเพราะ Setting::put เขียนทับทั้งก้อน
            // ฟอร์มรุ่นก่อนที่ยังไม่มีช่องเหล่านี้จึงต้องบันทึกผ่านได้โดยไม่ปิดโหมดทิ้งเงียบ ๆ
            'finance_strict_mode' => ['nullable', 'boolean'],
            'finance_slip_required_above' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'finance_require_category' => ['nullable', 'boolean'],
            'finance_close_requires_expense' => ['nullable', 'boolean'],
            'finance_close_requires_settled' => ['nullable', 'boolean'],
            // สั้นกว่า 1 วันคือบังคับปิดงบตั้งแต่วันที่กลับถึงบ้าน ยาวเกิน 90 วันก็ไม่เหลือความหมาย
            'finance_close_grace_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'finance_block_new_rounds' => ['nullable', 'boolean'],
            'chat_auto_answer_enabled' => ['nullable', 'boolean'],
        ]);

        if ($data['quiet_start_hour'] === $data['quiet_end_hour']) {
            return $this->error('ช่วงเวลางดรบกวนต้องไม่เริ่มและจบชั่วโมงเดียวกัน', 422);
        }

        $data['sos_sms_enabled'] = (bool) ($data['sos_sms_enabled'] ?? SiteSettings::bool('sos_sms_enabled'));

        $data['finance_close_grace_days'] = (int) ($data['finance_close_grace_days'] ?? SiteSettings::financeCloseGraceDays());

        foreach (['finance_strict_mode', 'finance_require_category', 'finance_close_requires_expense', 'finance_close_requires_settled', 'finance_block_new_rounds', 'chat_auto_answer_enabled'] as $flag) {
            $data[$flag] = (bool) ($data[$flag] ?? SiteSettings::bool($flag));
        }

        $data['finance_slip_required_above'] = (float) ($data['finance_slip_required_above'] ?? SiteSettings::get('finance_slip_required_above'));

        Setting::put(SiteSettings::KEY, $data);

        return $this->success([
            ...SiteSettings::all(),
            'licence_image_url' => SiteSettings::licenceImageUrl(),
        ], 'บันทึกการตั้งค่าระบบแล้ว');
    }
}
