<?php

namespace App\Models;

use App\Support\MediaDisk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * สถานที่ธรรมชาติหนึ่งแห่ง — อยู่ได้ด้วยตัวเองแม้ยังไม่มีทริปเปิดขาย
 */
class Place extends Model
{
    /** ประเภทสถานที่ที่ระบบรู้จัก (คีย์ => ป้ายภาษาไทย) */
    public const TYPES = [
        'mountain' => 'ภูเขา / ยอดดอย',
        'national_park' => 'อุทยานแห่งชาติ',
        'island' => 'เกาะ / ทะเล',
        'waterfall' => 'น้ำตก',
        'viewpoint' => 'จุดชมวิว',
        'other' => 'อื่น ๆ',
    ];

    /** ภาคเดียวกับที่ ConquestMapService ใช้ เพื่อให้กรองข้ามหน้ากันได้ */
    public const REGIONS = [
        'north' => 'ภาคเหนือ',
        'northeast' => 'ภาคอีสาน',
        'central' => 'ภาคกลาง',
        'east' => 'ภาคตะวันออก',
        'west' => 'ภาคตะวันตก',
        'south' => 'ภาคใต้',
    ];

    public const DIFFICULTIES = [
        'easy' => 'สายชิล',
        'medium' => 'ปานกลาง',
        'hard' => 'สายโหด',
    ];

    protected $fillable = [
        'name', 'slug', 'type', 'region', 'province', 'park',
        'latitude', 'longitude', 'elevation_m', 'trail_distance_km', 'elevation_gain_m', 'difficulty',
        'best_months', 'closed_months', 'season_note', 'closure_note',
        'summary', 'description', 'highlights', 'know_before',
        'cover_image', 'gallery', 'status', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'elevation_m' => 'integer',
            'trail_distance_km' => 'decimal:2',
            'elevation_gain_m' => 'integer',
            'best_months' => 'array',
            'closed_months' => 'array',
            'highlights' => 'array',
            'know_before' => 'array',
            'gallery' => 'array',
            'sort_order' => 'integer',
            'views_count' => 'integer',
        ];
    }

    public function trips(): BelongsToMany
    {
        return $this->belongsToMany(Trip::class, 'place_trip');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /** เปิดให้เข้าในเดือนนี้ไหม — เดือนที่ระบุว่าปิดถือว่าเข้าไม่ได้ */
    public function isClosedIn(int $month): bool
    {
        return in_array($month, $this->closed_months ?? [], true);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function regionLabel(): ?string
    {
        return $this->region ? (self::REGIONS[$this->region] ?? $this->region) : null;
    }

    public function difficultyLabel(): ?string
    {
        return $this->difficulty ? (self::DIFFICULTIES[$this->difficulty] ?? $this->difficulty) : null;
    }

    public function coverUrl(): ?string
    {
        return MediaDisk::url($this->cover_image);
    }

    /** @return array<int, string> */
    public function galleryUrls(): array
    {
        return collect($this->gallery ?? [])
            ->map(fn ($path) => MediaDisk::url(is_array($path) ? ($path['url'] ?? null) : $path))
            ->filter()
            ->values()
            ->all();
    }
}
