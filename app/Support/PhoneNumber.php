<?php

namespace App\Support;

/**
 * เบอร์โทรในระบบนี้ถูกพิมพ์เข้ามาจากหลายทาง — แอดมินพิมพ์จากแชท ลูกค้าพิมพ์ตอน
 * สมัคร ฟอร์มสาธารณะพิมพ์อีกที — จึงมีทั้ง 0812345678, 081-234-5678, +66812345678
 * ปนกันในคอลัมน์เดียว เทียบตรง ๆ ไม่เคยตรง
 *
 * ตัวนี้ทำให้ทุกแบบกลายเป็นรูปเดียว (ตัวเลขล้วน ขึ้นต้นด้วย 0) เพื่อ "เทียบ" และ
 * คืนรายการรูปแบบที่พบจริงเพื่อ "ค้นในฐานข้อมูล" — ค้นด้วย whereIn ของรูปแบบ
 * แทนที่จะ normalize ในฝั่ง SQL เพราะ REGEXP_REPLACE มีไม่ครบทุกตัวขับ (SQLite
 * ที่ใช้ในเทสต์ไม่มี)
 */
final class PhoneNumber
{
    /** เบอร์ไทยที่สมบูรณ์มี 9-10 หลัก สั้นกว่านี้ถือว่าไม่พอให้ยืนยันตัวตน */
    public const MIN_DIGITS = 9;

    /** ตัวเลขล้วนในรูปแบบในประเทศ (0XXXXXXXXX) — คืนค่าว่างถ้าเบอร์สั้นเกินไป */
    public static function normalise(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        // +66 81 234 5678 / 0066... — ตัดรหัสประเทศออกแล้วเติม 0 คืน
        if (str_starts_with($digits, '0066')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '66') && strlen($digits) >= 11) {
            $digits = substr($digits, 2);
        }

        if ($digits !== '' && ! str_starts_with($digits, '0') && strlen($digits) === 9) {
            $digits = '0'.$digits;
        }

        return strlen($digits) >= self::MIN_DIGITS ? $digits : '';
    }

    /**
     * รูปแบบที่ใช้ "อ่าน" เช่น 062-612-6006 — แอดมินกรอกมาแบบมีขีดหรือไม่มีก็ได้
     *
     * ต้องให้ผลเหมือน supportPhone() ใน resources/js/lib/contact.js เพื่อให้
     * เบอร์ในผลการค้นหากับเบอร์บนหน้าเว็บหน้าตาเหมือนกัน
     */
    public static function display(?string $phone): string
    {
        $raw = trim((string) $phone);

        if ($raw === '' || str_contains($raw, '-') || str_contains($raw, ' ')) {
            return $raw;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return match (strlen($digits)) {
            10 => substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6),
            9 => substr($digits, 0, 2).'-'.substr($digits, 2, 3).'-'.substr($digits, 5),
            default => $raw,
        };
    }

    /**
     * รูปแบบสากล +66XXXXXXXXX — สำหรับ structured data ที่ Google อ่าน
     *
     * คืนค่าว่างเมื่อเบอร์ไม่สมบูรณ์ ผู้เรียกจะได้เลือกซ่อนฟิลด์นั้นแทนที่จะ
     * ประกาศเบอร์พิการออกไป
     */
    public static function international(?string $phone): string
    {
        $local = self::normalise($phone);

        return $local === '' ? '' : '+66'.ltrim($local, '0');
    }

    /** เบอร์เดียวกันหรือไม่ — เบอร์ที่สั้นเกินไปตอบ false เสมอ ไม่ใช่ "ตรงกันหมด" */
    public static function matches(?string $a, ?string $b): bool
    {
        $left = self::normalise($a);
        $right = self::normalise($b);

        return $left !== '' && $left === $right;
    }

    /** 4 ตัวท้าย — หลักฐานเบา ๆ ที่หน้าค้นหาการจองใช้อยู่แล้ว */
    public static function last4(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return strlen($digits) >= 4 ? substr($digits, -4) : '';
    }

    /** จบด้วยเลข 4 ตัวเดียวกันหรือไม่ (ใช้กับหน้าที่ให้กรอกแค่ 4 ตัวท้าย) */
    public static function endsWithLast4(?string $stored, string $last4): bool
    {
        if (strlen($last4) < 4) {
            return false;
        }

        $digits = preg_replace('/\D+/', '', (string) $stored) ?? '';

        return $digits !== '' && str_ends_with($digits, $last4);
    }

    /**
     * รูปแบบที่เบอร์เดียวกันอาจถูกเก็บไว้ — เอาไปใส่ whereIn('phone', ...)
     *
     * @return array<int, string>
     */
    public static function variants(?string $phone): array
    {
        $local = self::normalise($phone);

        if ($local === '') {
            return array_values(array_filter([trim((string) $phone)]));
        }

        $body = ltrim($local, '0');
        $grouped = strlen($local) === 10
            ? substr($local, 0, 3).'-'.substr($local, 3, 3).'-'.substr($local, 6)
            : $local;

        return array_values(array_unique(array_filter([
            trim((string) $phone),
            $local,
            $grouped,
            str_replace('-', ' ', $grouped),
            '+66'.$body,
            '66'.$body,
            '+66 '.$body,
        ])));
    }
}
