<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** คนหนึ่งกด "รับทราบ" ประกาศหนึ่งแล้ว */
class ChatMessageAck extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['message_id', 'user_id'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
