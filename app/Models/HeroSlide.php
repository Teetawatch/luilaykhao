<?php

namespace App\Models;

use App\Models\Concerns\HasContentTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class HeroSlide extends Model
{
    use HasContentTranslations;

    /** ช่องที่ระบบแปลให้ลูกค้าต่างชาติ (ดู ContentTranslationService) */
    protected array $translatableContent = ['alt_text'];

    /**
     * รูปสไลด์หน้าแรกที่ส่งมากับ HTML shell (app.blade.php) — ถ้าให้หน้าแรกรอ
     * API /hero-slides ก่อน ทุกครั้งที่รีเฟรชจะเห็นรูปสำรองชุดเก่าวาบหนึ่งก่อน
     * แล้วค่อยสลับเป็นรูปจริง
     */
    public const PUBLIC_CACHE_KEY = 'hero_slides:public_urls';

    protected $fillable = [
        'image_url',
        'alt_text',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => static::flushPublicCache());
        static::deleted(fn () => static::flushPublicCache());
    }

    /** @return list<string> */
    public static function publicImageUrls(): array
    {
        try {
            return Cache::rememberForever(self::PUBLIC_CACHE_KEY, fn () => static::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->pluck('image_url')
                ->filter()
                ->values()
                ->all());
        } catch (\Throwable) {
            // ของประกอบหน้าเท่านั้น — ฐานข้อมูลตอบไม่ได้ หน้าแรกก็ไปถาม API เอง
            return [];
        }
    }

    public static function flushPublicCache(): void
    {
        Cache::forget(self::PUBLIC_CACHE_KEY);
    }
}
