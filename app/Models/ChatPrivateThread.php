<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * เธรด "ถามทีมงานแบบส่วนตัว" ของลูกทริปหนึ่งคนในรอบหนึ่ง — ดู ChatPrivateService
 */
class ChatPrivateThread extends Model
{
    protected $fillable = [
        'schedule_id', 'user_id', 'customer_read_id', 'staff_read_id', 'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'customer_read_id' => 'integer',
            'staff_read_id' => 'integer',
            'last_message_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    /** ลูกทริปเจ้าของเธรด */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatPrivateMessage::class, 'thread_id');
    }
}
