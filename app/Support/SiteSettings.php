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
        // ช่วงเวลางดยิง push การตลาด (กันปลุกลูกค้าตอนดึก)
        'quiet_hours_enabled' => true,
        'quiet_start_hour' => 21,
        'quiet_end_hour' => 8,
        // ข้อมูลติดต่อที่แสดงให้ลูกค้า
        'support_phone' => null,
        'support_line' => null,
        'support_email' => null,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = Setting::get(self::KEY, []);

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
}
