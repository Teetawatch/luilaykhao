<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** คำแปลของข้อความแชทส่วนตัว / ซัพพอร์ต — ดู ChatTranslationService */
class ConversationMessageTranslation extends Model
{
    protected $fillable = [
        'translatable_type', 'translatable_id', 'lang', 'body_hash', 'text', 'source_lang', 'same_language',
    ];

    protected function casts(): array
    {
        return [
            'same_language' => 'boolean',
        ];
    }

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}
