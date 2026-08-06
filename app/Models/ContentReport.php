<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * รายงานเนื้อหาหนึ่งใบ — ใครรายงานอะไร ด้วยเหตุผลอะไร และแอดมินจัดการหรือยัง
 *
 * `reportable_type` เป็นคีย์สั้นของ ModerationService (chat_message, review, …)
 * ไม่ใช่ morph map ของ Eloquent เพราะเนื้อหาแต่ละชนิดซ่อนคนละวิธี
 */
class ContentReport extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_ACTIONED = 'actioned';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'reporter_id', 'reportable_type', 'reportable_id', 'author_id',
        'reason', 'note', 'status', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
