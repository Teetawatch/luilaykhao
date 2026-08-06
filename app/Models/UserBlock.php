<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "ฉันไม่อยากเห็นคนนี้อีก" — บล็อกทางเดียว ผู้ถูกบล็อกไม่รู้ตัว
 *
 * ผลของมันเป็นสองทางเสมอ (ดู ModerationService::hiddenAuthorIds) เพราะถ้าซ่อน
 * ให้ฝ่ายเดียว อีกฝ่ายจะยังตอบกลับข้อความที่ตัวเองมองไม่เห็นได้
 */
class UserBlock extends Model
{
    protected $fillable = ['blocker_id', 'blocked_id'];

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocker_id');
    }

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }
}
