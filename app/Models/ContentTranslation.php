<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * คำแปลหนึ่งช่องของเนื้อหาที่แอดมินกรอก — ดู App\Services\ContentTranslationService
 */
class ContentTranslation extends Model
{
    protected $fillable = [
        'translatable_type', 'translatable_id', 'locale', 'path',
        'source_hash', 'source_text', 'text', 'is_manual', 'edited_by',
    ];

    protected function casts(): array
    {
        return [
            'translatable_id' => 'integer',
            'is_manual' => 'boolean',
        ];
    }

    public static function hash(string $thai): string
    {
        return sha1(trim($thai));
    }

    /** ข้อความไทยตอนนี้ยังตรงกับที่แปลไว้ไหม */
    public function matches(string $thai): bool
    {
        return $this->source_hash === self::hash($thai);
    }
}
