<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ชื่อ-นามสกุลของคนไทยต้องเป็นอักษรไทยตามบัตรประชาชน และมีทั้งชื่อและนามสกุล
 *
 * มีเพราะรายชื่อชุดนี้ถูกส่งไปทำประกันการเดินทาง ซึ่งรับเฉพาะชื่อไทยที่ตรงกับ
 * บัตรประชาชน ลูกค้าจำนวนมากพิมพ์ชื่ออังกฤษมา (หรือเติมอัตโนมัติจากชื่อ LINE)
 * แล้วทีมงานต้องไล่ถามใหม่ทีละคนก่อนวันเดินทาง — ดักตั้งแต่ตอนกรอกถูกกว่ามาก
 *
 * ใช้เฉพาะกับคนสัญชาติไทย ชาวต่างชาติไม่มีชื่อไทยให้กรอก ตรรกะเดียวกับ
 * thaiNameError() ในหน้าจองบนเว็บ และ thaiNameError() ในแอป
 */
class ThaiName implements ValidationRule
{
    public const NOT_THAI = 'กรุณากรอกชื่อ-นามสกุลเป็นภาษาไทยตามบัตรประชาชน (ใช้ส่งทำประกันการเดินทาง)';

    public const NO_SURNAME = 'กรุณากรอกทั้งชื่อและนามสกุล เว้นวรรคระหว่างชื่อกับนามสกุล';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value) || ! is_string($value)) {
            return; // จำเป็นหรือไม่ ให้กฎ required ตัดสิน
        }

        if ($message = self::problem($value)) {
            $fail($message);
        }
    }

    /** ข้อความที่ต้องแจ้งลูกค้า หรือ null ถ้าชื่อนี้ใช้ได้ */
    public static function problem(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        // อักษรไทย + เว้นวรรค + จุด/ขีด (ชื่อย่อ, นามสกุลที่มีขีด) เท่านั้น
        // ตัวเลขและอักษรละตินแปลว่าไม่ใช่ชื่อตามบัตร
        if (! preg_match('/^[\x{0E00}-\x{0E7F} .\-]+$/u', $name)
            || ! preg_match('/[\x{0E01}-\x{0E2E}]/u', $name)) {
            return self::NOT_THAI;
        }

        if (! str_contains($name, ' ')) {
            return self::NO_SURNAME;
        }

        return null;
    }
}
