<?php

namespace App\Support;

/**
 * ภาษาที่เนื้อหาจากแอดมิน (ชื่อทริป FAQ จุดรับ …) ควรออกไปใน request นี้
 *
 * null = ส่งข้อความไทยตามที่บันทึกไว้ (ไม่แตะฐานข้อมูลคำแปลเลย หน้าไทยจึงไม่ช้าลง)
 *
 * หน้าแอดมิน/สตาฟ/คนขับได้ไทยเสมอแม้เบราว์เซอร์จะจำภาษาอังกฤษไว้ — หน้าพวกนั้น
 * อ่านค่ากลับไปเติมฟอร์มแก้ไข ถ้าส่งคำแปลไป กดบันทึกทีเดียวต้นฉบับไทยจะถูก
 * เขียนทับด้วยภาษาอังกฤษ
 */
class ContentLocale
{
    /** ภาษาที่ระบบแปลเนื้อหาให้ — เพิ่มภาษาจีนวันหลังที่นี่ + ContentTranslationService::LANGUAGES */
    public const LOCALES = ['en'];

    /** path ของ API ที่ใช้แก้ข้อมูล — ต้องเห็นต้นฉบับไทยเสมอ */
    private const EDITING_PATHS = ['api/v1/admin', 'api/v1/admin/*', 'api/v1/staff/*', 'api/v1/driver/*'];

    private static bool $disabled = false;

    public static function current(): ?string
    {
        if (self::$disabled) {
            return null;
        }

        $locale = app()->getLocale();
        if (! in_array($locale, self::LOCALES, true)) {
            return null;
        }

        if (app()->bound('request') && request()->is(...self::EDITING_PATHS)) {
            return null;
        }

        return $locale;
    }

    public static function active(): bool
    {
        return self::current() !== null;
    }

    /**
     * ทำงานโดยไม่แปลเนื้อหา — สำหรับโค้ดที่อ่านค่าไปเขียนกลับ หรือเทียบกับค่าที่เก็บ
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function original(callable $callback): mixed
    {
        $previous = self::$disabled;
        self::$disabled = true;

        try {
            return $callback();
        } finally {
            self::$disabled = $previous;
        }
    }
}
