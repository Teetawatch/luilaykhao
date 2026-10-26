<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * หนึ่งใบในคลังเกมรอบวง — สถานที่ของสายลับ, โจทย์ความจริง/ท้า, ฉันไม่เคย,
 * คำใบ้ คำถามควิซ หรือคำต้องห้าม ดู [PartyGameService] สำหรับรูปร่าง data ของแต่ละเกม
 */
class PartyGameCard extends Model
{
    public const GAMES = ['spyfall', 'truth_dare', 'never', 'heads_up', 'trivia', 'forbidden'];

    public const LEVELS = ['light', 'medium', 'spicy'];

    public const KINDS = ['truth', 'dare'];

    protected $fillable = [
        'game', 'kind', 'level', 'category', 'body', 'data',
        'trip_id', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'trip_id' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
