<?php

namespace App\Models;

use App\Models\Concerns\HasContentTranslations;
use App\Support\MediaDisk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StayRoomType extends Model
{
    use HasContentTranslations;

    /** ช่องที่ระบบแปลให้ลูกค้าต่างชาติ (ดู ContentTranslationService) */
    protected array $translatableContent = ['name', 'description'];

    protected $fillable = [
        'stay_id', 'name', 'description', 'units', 'base_guests', 'max_guests',
        'price_per_night', 'weekend_price', 'extra_guest_price', 'breakfast_included',
        'photos', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'base_guests' => 'integer',
            'max_guests' => 'integer',
            'price_per_night' => 'decimal:2',
            'weekend_price' => 'decimal:2',
            'extra_guest_price' => 'decimal:2',
            'breakfast_included' => 'boolean',
            'photos' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function nights(): HasMany
    {
        return $this->hasMany(StayRoomNight::class, 'room_type_id');
    }

    /** @return array<int, string> */
    public function photoUrls(): array
    {
        return collect($this->photos ?? [])
            ->map(fn ($path) => MediaDisk::url(is_array($path) ? ($path['url'] ?? null) : $path))
            ->filter()
            ->values()
            ->all();
    }
}
