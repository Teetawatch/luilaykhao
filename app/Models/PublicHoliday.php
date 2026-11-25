<?php

namespace App\Models;

use App\Support\AppLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * วันหยุดราชการหนึ่งวัน — ดู HolidayCalendarService (ดึง) และ LongWeekendService (ใช้)
 */
class PublicHoliday extends Model
{
    public const SOURCE_GOOGLE = 'google';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'date', 'name', 'name_en', 'official_name', 'source', 'external_id', 'is_active', 'name_locked',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_active' => 'boolean',
            'name_locked' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** ชื่อตามภาษาของ request */
    public function label(): string
    {
        return AppLocale::isEnglish() && filled($this->name_en) ? (string) $this->name_en : (string) $this->name;
    }
}
