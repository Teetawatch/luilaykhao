<?php

namespace App\Models;

use App\Models\Concerns\HasContentTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleAnnouncement extends Model
{
    use HasContentTranslations;

    /** ช่องที่ระบบแปลให้ลูกค้าต่างชาติ (ดู ContentTranslationService) */
    protected array $translatableContent = ['title', 'body'];

    protected $fillable = [
        'schedule_id', 'author_id', 'category', 'title', 'body', 'is_pinned',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
