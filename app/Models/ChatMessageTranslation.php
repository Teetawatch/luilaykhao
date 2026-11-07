<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessageTranslation extends Model
{
    protected $fillable = [
        'message_id', 'lang', 'body_hash', 'text', 'source_lang', 'same_language',
    ];

    protected function casts(): array
    {
        return [
            'same_language' => 'boolean',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }
}
