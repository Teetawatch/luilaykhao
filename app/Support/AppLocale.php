<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

/**
 * ภาษาของข้อความที่ server สร้างให้ลูกค้า
 *
 * ภาษาไทยคือภาษาต้นฉบับ: ข้อความในโค้ดเขียนเป็นไทย แล้วครอบด้วย `__()` ส่วน
 * คำแปลอยู่ใน `lang/en.json` ที่ใช้ข้อความไทยเป็นคีย์ ข้อความที่ยังไม่มีคำแปล
 * จึงออกมาเป็นไทยเหมือนเดิม และลูกค้าภาษาไทยไม่เห็นอะไรเปลี่ยน
 *
 * ภาษามาจาก header `X-App-Language` ที่แอปส่งเท่านั้น ไม่อ่าน `Accept-Language`
 * เพราะเว็บเรียก API ชุดเดียวกัน และเบราว์เซอร์ของคนไทยจำนวนมากตั้งเป็น
 * อังกฤษ ถ้าอ่านตัวนั้น ลูกค้าเว็บจะได้ error และ push เป็นภาษาอังกฤษโดยไม่ได้เลือก
 */
class AppLocale
{
    public const THAI = 'th';

    public const ENGLISH = 'en';

    public const SUPPORTED = [self::THAI, self::ENGLISH];

    public const HEADER = 'X-App-Language';

    /** ภาษาที่แอปขอมากับ request นี้ หรือ null เมื่อไม่ได้ส่งมา/ส่งค่าที่ไม่รู้จัก */
    public static function fromRequest(Request $request): ?string
    {
        return self::normalise($request->header(self::HEADER));
    }

    /** ภาษาสำหรับส่งข้อความหาผู้ใช้คนนี้นอก request (push, LINE, job) */
    public static function forUser(?User $user): string
    {
        return self::normalise($user?->locale) ?? self::THAI;
    }

    public static function normalise(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        // "en", "en-US", "en_GB" → en
        $code = strtolower(substr(trim($value), 0, 2));

        return in_array($code, self::SUPPORTED, true) ? $code : null;
    }

    public static function isEnglish(): bool
    {
        return app()->getLocale() === self::ENGLISH;
    }

    /**
     * วันที่สั้นตามภาษาของ request: "25 ก.ค. 2569" หรือ "25 Jul 2026"
     */
    public static function shortDate(\DateTimeInterface $date): string
    {
        return self::isEnglish()
            ? Carbon::instance($date)->format('j M Y')
            : ThaiDate::short($date);
    }

    /**
     * ทำงาน $callback ด้วยภาษาของผู้ใช้ แล้วคืนภาษาเดิม — ใช้ตอนประกอบ push
     * หรือข้อความที่จะส่งหาคนอื่นจากใน request ของอีกคน
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withUser(?User $user, callable $callback): mixed
    {
        $previous = App::getLocale();
        App::setLocale(self::forUser($user));

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }
}
