<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Site-wide operating numbers that used to live as PHP constants — the seat
 * count that guarantees a departure, when a round counts as "almost full",
 * the quiet-hours window for marketing pushes, and the contact details shown
 * to customers.
 *
 * Stored as one JSON block under the `site` key so admins can tune them at
 * runtime without a deploy. Every getter falls back to the same default the
 * constant used to hold, so an empty store behaves exactly like before.
 */
class SiteSettings
{
    public const KEY = 'site';

    public const DEFAULTS = [
        // จำนวนที่นั่งที่ทำให้รอบ "การันตีออกเดินทาง"
        'guarantee_min_seats' => 8,
        // เหลือกี่ที่นั่งจึงถือว่าใกล้เต็ม (ยิง push เร่งการจอง)
        'low_seat_threshold' => 3,
        // รอบที่จองน้อยกว่านี้ = เสี่ยงไม่ออก → เตือนทีมงานล่วงหน้า
        'underfilled_min_seats' => 8,
        // ได้สิทธิ์จากคิวรอแล้วมีเวลาจองกี่นาที ก่อนที่นั่งจะตกถึงคนถัดไป
        'waitlist_offer_ttl_minutes' => 15,
        // ช่วงเวลางดยิง push การตลาด (กันปลุกลูกค้าตอนดึก)
        'quiet_hours_enabled' => true,
        'quiet_start_hour' => 21,
        'quiet_end_hour' => 8,
        // ข้อมูลติดต่อที่แสดงให้ลูกค้า
        'support_phone' => null,
        'support_line' => null,
        'support_email' => null,
        // ใบอนุญาตประกอบธุรกิจนำเที่ยว — เลขที่และรูปใบจริงที่ลูกค้ากดดูได้
        // เลข 11 ขึ้นต้น = นำเที่ยวได้ทั้งในและต่างประเทศ
        'licence_no' => '11/13855',
        'licence_image' => null,
    ];

    /**
     * @return array<string, mixed>
     */
    /**
     * ค่าที่ใช้จริง = ค่าตั้งต้น ทับด้วยค่าที่แอดมินบันทึกไว้
     *
     * อ่านที่เก็บไม่ได้ (ยังไม่ migrate, ฐานข้อมูลสะดุด) ให้ถอยไปใช้ค่าตั้งต้น
     * แทนที่จะโยน exception — ตั้งแต่เลขที่ใบอนุญาตย้ายมาอยู่ที่นี่ ทุกหน้าเว็บ
     * ก็อ่านค่าชุดนี้ตอนเรนเดอร์ การล้มตรงนี้จึงหมายถึงทั้งเว็บ 500 เพราะเรื่อง
     * ที่แค่แสดงผลเพี้ยนก็พอ
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        try {
            $stored = Setting::get(self::KEY, []);
        } catch (\Throwable) {
            return self::DEFAULTS;
        }

        return array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::all()[$key] ?? null;

        return $value ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function bool(string $key): bool
    {
        return (bool) self::get($key);
    }

    /** Contact phone, falling back to the deploy-time config value. */
    public static function supportPhone(): string
    {
        return (string) (self::get('support_phone') ?: config('app.support_phone'));
    }

    /**
     * เลขที่ใบอนุญาตนำเที่ยว — แหล่งเดียวของทั้งเว็บ แอป และ structured data
     *
     * เคยฮาร์ดโค้ดไว้ 13 ที่ ทำให้ตอนเปลี่ยนใบต้องไล่แก้ทีละไฟล์แล้ว deploy ใหม่
     */
    public static function licenceNo(): string
    {
        return (string) (self::get('licence_no') ?: self::DEFAULTS['licence_no']);
    }

    /**
     * รูปใบอนุญาตที่ลูกค้ากดดูได้ — คืน URL เต็มเสมอ
     *
     * ยังไม่เคยอัปโหลดทับ = ใช้ไฟล์เดิมที่ /images/cer.jpg ต่อไป แอปเวอร์ชันที่
     * ยังไม่ได้อัปเดตจึงไม่เห็นรูปหาย
     */
    public static function licenceImageUrl(): string
    {
        $stored = (string) (self::get('licence_image') ?: '');

        // ไฟล์เดิมอยู่ใน public/ ไม่ได้อยู่บน media disk จึงต้องใช้ url() ตรง ๆ
        if ($stored === '') {
            return url(self::LEGACY_LICENCE_IMAGE);
        }

        // ค่าที่อัปโหลดใหม่เป็น URL เต็มจาก R2 อยู่แล้ว ส่วน local dev ได้ path
        return MediaDisk::url($stored) ?: url($stored);
    }

    /** รูปใบอนุญาตชุดเดิมก่อนมีหน้าอัปโหลดในแอดมิน */
    private const LEGACY_LICENCE_IMAGE = '/images/cer.jpg';
}
