<?php

namespace App\Rules;

use App\Support\Countries;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * รับเฉพาะรหัสประเทศที่อยู่ในทะเบียนของเรา
 *
 * ไม่ปล่อยให้พิมพ์รหัส ISO อะไรก็ได้ เพราะทุกประเทศที่รับต้องมีชื่อไทยและ
 * เขตเวลาที่ตรวจแล้วใน `Countries` ไม่งั้นหน้าเว็บจะโชว์รหัสดิบให้ลูกค้าเห็น
 */
class KnownCountry implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return; // ความจำเป็นของช่องนี้ให้กฎ required ตัดสิน
        }

        if (! Countries::exists((string) $value)) {
            $fail('ยังไม่รองรับประเทศนี้ กรุณาเพิ่มลงทะเบียนประเทศก่อน');
        }
    }
}
