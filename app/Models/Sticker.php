<?php

namespace App\Models;

use App\Support\MediaDisk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sticker extends Model
{
    /** body สำรองของข้อความสติกเกอร์ — แอปรุ่นเก่า/เว็บ/push เห็นบรรทัดนี้แทนรูป */
    public const BODY_PREFIX = '[สติกเกอร์]';

    protected $fillable = [
        'pack_id', 'label', 'keywords', 'image_path', 'width', 'height',
        'is_animated', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'is_animated' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(StickerPack::class, 'pack_id');
    }

    public function getUrlAttribute(): ?string
    {
        return MediaDisk::url($this->image_path);
    }

    /** "[สติกเกอร์] รับทราบ" */
    public function fallbackBody(): string
    {
        return self::BODY_PREFIX.' '.$this->label;
    }

    /**
     * คำค้นทั้งหมดของสติกเกอร์นี้ (ป้าย + คำที่แอดมินใส่) ตัดซ้ำ ตัดช่องว่าง
     *
     * @return array<int, string>
     */
    public function keywordList(): array
    {
        return collect(explode(',', (string) $this->keywords))
            ->prepend($this->label)
            ->map(fn ($k) => trim((string) $k))
            ->filter(fn ($k) => $k !== '')
            ->unique(fn ($k) => mb_strtolower($k))
            ->values()
            ->all();
    }

    /** ส่งได้ไหม — ตัวสติกเกอร์และชุดของมันต้องเปิดอยู่ทั้งคู่ */
    public function isUsable(): bool
    {
        return $this->is_active && (bool) $this->pack?->is_active;
    }
}
