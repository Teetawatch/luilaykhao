<?php

namespace App\Models;

use App\Support\MediaDisk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SupportMessage extends Model
{
    protected $fillable = [
        'conversation_id', 'user_id', 'sender_role', 'body', 'image_path',
    ];

    /** คำแปลอัตโนมัติ ภาษาละแถว — ดู ChatTranslationService */
    public function translations(): MorphMany
    {
        return $this->morphMany(ConversationMessageTranslation::class, 'translatable');
    }

    public function getImageUrlAttribute(): ?string
    {
        return MediaDisk::url($this->image_path);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
