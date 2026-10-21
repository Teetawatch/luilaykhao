<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** การ์ดแนะนำตัวของลูกทริปในห้องแชท — หนึ่งใบต่อคนต่อรอบ */
class ChatMemberIntro extends Model
{
    /** ระดับประสบการณ์ — ค่าตรงกับแอป (chat_intro.dart) */
    public const EXPERIENCES = [
        'first' => 'มาครั้งแรก 🌱',
        'some' => 'เคยไปมาบ้าง 🥾',
        'lots' => 'สายลุย ⛰️',
    ];

    protected $fillable = ['schedule_id', 'user_id', 'message_id', 'hometown', 'experience', 'note'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function experienceLabel(): ?string
    {
        return self::EXPERIENCES[$this->experience] ?? null;
    }
}
