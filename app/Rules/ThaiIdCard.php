<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ตรวจเลขบัตรประชาชนไทยด้วยหลักตรวจสอบ (checksum) ไม่ใช่แค่นับ 13 หลัก
 *
 * มีเพราะเลขบัตรที่พิมพ์ผิดจะไปโผล่ตอนทำประกันการเดินทาง ซึ่งสายเกินกว่าจะ
 * ตามแก้ทัน ดักตั้งแต่ตอนลูกค้ากรอกถูกกว่ามาก — ตรรกะเดียวกับ isValidThaiId()
 * ในหน้าจองบนเว็บ
 */
class ThaiIdCard implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return; // จำเป็นหรือไม่ ให้กฎ required ตัดสิน
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        if (strlen($digits) !== 13) {
            $fail('เลขบัตรประชาชนต้องมี 13 หลัก');

            return;
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $digits[$i] * (13 - $i);
        }

        if ((11 - ($sum % 11)) % 10 !== (int) $digits[12]) {
            $fail('เลขบัตรประชาชนไม่ถูกต้อง ลองตรวจสอบอีกครั้งครับ');
        }
    }
}
