<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Slug ที่เก็บอักษรไทยไว้ — Str::slug ของ Laravel ตัดอักษรไทยทิ้งทั้งหมด
 * ทำให้ชื่อเรื่อง/ชื่อสถานที่ภาษาไทยล้วนเหลือแค่ค่าว่าง
 */
class ThaiSlug
{
    /**
     * แปลงข้อความเป็น slug — \p{M} ต้องมีด้วย เพราะสระและวรรณยุกต์ไทยเป็น
     * combining mark ไม่ใช่ letter ถ้าไม่เก็บไว้คำจะเพี้ยน
     */
    public static function make(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '-', $value) ?? '';

        return trim($value, '-');
    }

    /**
     * slug ที่ไม่ซ้ำในตาราง — ต่อท้ายด้วยเลขเมื่อชนกับของเดิม
     *
     * @param  callable(string): bool  $exists  คืน true เมื่อ slug นี้ถูกใช้ไปแล้ว
     */
    public static function unique(string $source, callable $exists, string $fallbackPrefix = 'item'): string
    {
        $base = self::make($source);

        if ($base === '') {
            $base = $fallbackPrefix.'-'.Str::lower(Str::random(5));
        }

        $slug = $base;
        $suffix = 2;

        while ($exists($slug)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
