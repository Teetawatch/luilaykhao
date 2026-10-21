<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatTrailPosition extends Model
{
    protected $fillable = [
        'check_id', 'passenger_id', 'reached_index', 'reached_at', 'marked_by_id',
        'help_at', 'help_note', 'help_acked_at',
    ];

    protected function casts(): array
    {
        return [
            'reached_index' => 'integer',
            'reached_at' => 'datetime',
            'help_at' => 'datetime',
            'help_acked_at' => 'datetime',
        ];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(ChatTrailCheck::class, 'check_id');
    }

    /** ขอพักอยู่และทีมงานยังไม่รับทราบ */
    public function needsHelp(): bool
    {
        return $this->help_at !== null && $this->help_acked_at === null;
    }
}
