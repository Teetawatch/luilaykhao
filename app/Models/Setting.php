<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Simple key-value store for site-wide toggles/config that admins edit at
 * runtime (e.g. the urgent-trips popup). Values are JSON, so a key can hold
 * a scalar or a whole settings block.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        // ห่อค่าไว้ใน array — Cache::remember ไม่เก็บ null จึงเคยถามฐานข้อมูลทุกครั้ง
        // สำหรับค่าที่แอดมินไม่เคยตั้ง (ซึ่งคือส่วนใหญ่) หน้ารายการทริปถามรอบละ 3 คีย์
        $cached = Cache::remember(
            self::cacheKey($key),
            now()->addMinutes(10),
            fn () => ['value' => static::where('key', $key)->value('value')]
        );

        return $cached['value'] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        self::forget($key);
    }

    /** ล้างแคชของคีย์หนึ่ง — ใช้เมื่อลบแถวทิ้งตรง ๆ โดยไม่ผ่าน put() */
    public static function forget(string $key): void
    {
        Cache::forget(self::cacheKey($key));
        // รูปแบบเก่า (ค่าดิบ ไม่ห่อ) ที่อาจยังค้างอยู่ในแคชตอนเพิ่ง deploy
        Cache::forget("setting:{$key}");
    }

    private static function cacheKey(string $key): string
    {
        return "setting:v2:{$key}";
    }
}
