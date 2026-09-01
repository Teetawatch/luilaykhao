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
        // ยิง SMS หาสตาฟ/คนขับ/ทีมออฟฟิศเมื่อมีเคส SOS — ช่องทางเดียวที่ไปถึง
        // เครื่องที่ไม่มี data ปิดได้เมื่อเครดิต SMS หมดโดยไม่กระทบ push/อีเมล
        'sos_sms_enabled' => true,
        // ข้อมูลติดต่อที่แสดงให้ลูกค้า
        'support_phone' => null,
        'support_line' => null,
        'support_email' => null,
        // ใบอนุญาตประกอบธุรกิจนำเที่ยว — เลขที่และรูปใบจริงที่ลูกค้ากดดูได้
        // เลข 11 ขึ้นต้น = นำเที่ยวได้ทั้งในและต่างประเทศ
        'licence_no' => '11/13855',
        'licence_image' => null,
        // ── บัญชีทริป (โหมดเข้มงวด) ────────────────────────────────
        // ปิดโหมดนี้ = กลับไปเป็นสมุดบันทึกหลวม ๆ แบบเดิม ทุกข้อบังคับด้านล่างจะไม่ทำงาน
        'finance_strict_mode' => true,
        // รายจ่ายเกินกี่บาทต้องแนบสลิป/ใบเสร็จ (0 = ไม่บังคับ)
        'finance_slip_required_above' => 1000,
        // ทุกรายการต้องระบุหมวด
        'finance_require_category' => true,
        // ปิดงบไม่ได้ถ้ายังไม่มีรายจ่ายสักรายการ (กันกำไรลวงตา 100%)
        'finance_close_requires_expense' => true,
        // ปิดงบไม่ได้ถ้ายังมีบุ๊คกิ้งค้างชำระ
        'finance_close_requires_settled' => true,
        // ทริปจบแล้วกี่วันต้องปิดงบให้เสร็จ — เลยกำหนดถือว่า "ค้างปิดงบ"
        'finance_close_grace_days' => 7,
        // มีรอบค้างปิดงบ = เปิดรอบใหม่ของทริปนั้นไม่ได้ จนกว่าจะเคลียร์
        'finance_block_new_rounds' => true,
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

    /** LINE ID ที่แสดงให้ลูกค้า เช่น `@luilaykhao` */
    public static function supportLine(): string
    {
        return (string) (self::get('support_line') ?: config('app.support_line_id'));
    }

    /**
     * ลิงก์เปิดแชท LINE — แอดมินกรอก LINE ID ไม่ใช่ URL จึงประกอบให้เอง
     *
     * ถ้าวาง URL เต็มมาก็ใช้ตามนั้น เพราะบัญชีบางแบบมีลิงก์เชิญเฉพาะของตัวเอง
     * ที่ประกอบจาก ID ไม่ได้
     */
    public static function supportLineUrl(): string
    {
        $line = trim(self::supportLine());

        if (str_starts_with($line, 'http://') || str_starts_with($line, 'https://')) {
            return $line;
        }

        $id = ltrim($line, '@');

        return $id === ''
            ? (string) config('app.support_line_url')
            : 'https://line.me/R/ti/p/@'.$id;
    }

    /** อีเมลติดต่อที่แสดงให้ลูกค้า */
    public static function supportEmail(): string
    {
        return (string) (self::get('support_email') ?: config('app.support_email'));
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

    /** โหมดบัญชีเข้มงวด — ปิดแล้วข้อบังคับด้านการเงินทั้งหมดหยุดทำงาน */
    public static function financeStrict(): bool
    {
        return self::bool('finance_strict_mode');
    }

    /**
     * รายจ่ายเกินยอดนี้ต้องมีหลักฐาน — คืน null เมื่อไม่บังคับ
     * (ปิดโหมดเข้มงวด หรือตั้งเพดานเป็น 0)
     */
    public static function financeSlipRequiredAbove(): ?float
    {
        if (! self::financeStrict()) {
            return null;
        }

        $above = (float) self::get('finance_slip_required_above');

        return $above > 0 ? $above : null;
    }

    public static function financeRequiresCategory(): bool
    {
        return self::financeStrict() && self::bool('finance_require_category');
    }

    /** ทริปจบแล้วกี่วันถึงถือว่าค้างปิดงบ — อย่างน้อย 1 วันเสมอ */
    public static function financeCloseGraceDays(): int
    {
        return max(1, (int) self::get('finance_close_grace_days'));
    }

    /** ห้ามเปิดรอบใหม่ของทริปที่ยังมีรอบค้างปิดงบหรือไม่ */
    public static function financeBlocksNewRounds(): bool
    {
        return self::financeStrict() && self::bool('finance_block_new_rounds');
    }

    /** รูปใบอนุญาตชุดเดิมก่อนมีหน้าอัปโหลดในแอดมิน */
    private const LEGACY_LICENCE_IMAGE = '/images/cer.jpg';
}
