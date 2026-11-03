<?php

namespace App\Models;

use App\Services\PassportPinService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * หมุดความทรงจำที่ผู้ใช้ปักเองในสมุดสะสมการเดินทาง — เห็นได้เฉพาะเจ้าของ
 * {@see PassportPinService} เป็นทางเดียวที่เขียน
 */
class PassportPin extends Model
{
    public const SOURCES = ['gps', 'map', 'trip'];

    protected $fillable = [
        'user_id', 'trip_id', 'title', 'note', 'latitude', 'longitude',
        'visited_on', 'source',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'visited_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // ลบทีละรูปผ่านโมเดล ไม่ปล่อยให้ cascade ของฐานข้อมูลลบแถว — ไม่งั้นไฟล์
        // บน R2 ค้างอยู่โดยไม่มีใครอ้างถึง
        static::deleting(function (PassportPin $pin) {
            $pin->photos()->get()->each->delete();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(PassportPinPhoto::class)->orderBy('sort_order')->orderBy('id');
    }
}
