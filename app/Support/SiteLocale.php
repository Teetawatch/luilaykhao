<?php

namespace App\Support;

/**
 * ภาษาของหน้าเว็บ (Vue SPA) ที่ server เสิร์ฟ — ไทย หรืออังกฤษใต้ /en/…
 *
 * เว็บแยกภาษาด้วย URL: หน้าไทยใช้ URL เดิมทั้งหมด หน้าอังกฤษอยู่ใต้ /en
 * (ฝั่ง JS ดู resources/js/i18n/index.js) ที่นี่ทำส่วนที่ JS ทำแทนไม่ได้ คือ
 * <html lang>, canonical, hreflang และหัวเรื่อง/คำโปรยที่บ็อตแชร์ลิงก์อ่าน
 * ก่อน JavaScript จะรัน
 *
 * คำแปลอ่านจาก resources/js/i18n/en.json ไฟล์เดียวกับหน้าเว็บ — ข้อความ SEO ใน
 * config/seo.php ตรงกับ router ของ SPA ทุกตัวอักษรอยู่แล้ว (SeoPageMetaSyncTest)
 * จึงไม่ต้องดูแลคำแปลสองชุด ส่วนข้อความที่ API ตอบ (error, push) ยังใช้
 * lang/en.json ผ่าน __() เหมือนเดิม
 */
class SiteLocale
{
    public const THAI = 'th';

    public const ENGLISH = 'en';

    private const MESSAGES = 'resources/js/i18n/en.json';

    /** @var array<string, string>|null */
    private static ?array $messages = null;

    /** ภาษาจาก path ที่ขอ: "en", "en/trips/x" → en, อย่างอื่น → th */
    public static function fromPath(string $path): string
    {
        return preg_match('#^/?en(?:/|$)#', $path) ? self::ENGLISH : self::THAI;
    }

    /** path ไม่มี /en นำหน้า เริ่มด้วย "/" เสมอ: "en/trips/x" → "/trips/x" */
    public static function strip(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $stripped = preg_replace('#^/en(?=/|$)#', '', $path);

        return $stripped === '' ? '/' : $stripped;
    }

    /** URL เต็มของ path (ไม่มี /en) ในภาษาที่ระบุ */
    public static function url(string $path, ?string $locale = null): string
    {
        $path = self::strip($path);
        $locale ??= self::current();

        if ($locale !== self::ENGLISH) {
            return url($path);
        }

        return url($path === '/' ? '/en' : '/en'.$path);
    }

    /** @return array<string, string> hreflang => URL */
    public static function alternates(string $path): array
    {
        return [
            'th' => self::url($path, self::THAI),
            'en' => self::url($path, self::ENGLISH),
            'x-default' => self::url($path, self::THAI),
        ];
    }

    public static function current(): string
    {
        return app()->getLocale() === self::ENGLISH ? self::ENGLISH : self::THAI;
    }

    public static function isEnglish(): bool
    {
        return self::current() === self::ENGLISH;
    }

    /**
     * ข้อความไทยในภาษาของหน้า — ไม่มีคำแปล (หรือหน้าไทย) = ข้อความเดิม
     * รองรับ {ชื่อ} แบบเดียวกับ $t() ฝั่ง JS
     *
     * @param  array<string, string|int|float>  $params
     */
    public static function text(string $thai, array $params = []): string
    {
        $text = $thai;

        if (self::isEnglish()) {
            $translated = self::messages()[$thai] ?? null;
            if (is_string($translated) && $translated !== '') {
                // หน้าเซิร์ฟเวอร์ไม่มีตัวเลขให้เลือกรูปพหูพจน์ — ใช้รูปสุดท้าย (พหูพจน์)
                $forms = explode(' || ', $translated);
                $text = end($forms);
            }
        }

        foreach ($params as $name => $value) {
            $text = str_replace('{'.$name.'}', (string) $value, $text);
        }

        return $text;
    }

    /** @return array<string, string> */
    private static function messages(): array
    {
        if (self::$messages === null) {
            $raw = @file_get_contents(base_path(self::MESSAGES));
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            self::$messages = is_array($decoded) ? $decoded : [];
        }

        return self::$messages;
    }
}
