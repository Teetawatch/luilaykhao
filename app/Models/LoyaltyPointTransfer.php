<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ใบโอนแต้มให้เพื่อนหนึ่งใบ — การเคลื่อนไหวของแต้มจริงอยู่ที่ LoyaltyTransaction. */
class LoyaltyPointTransfer extends Model
{
    protected $fillable = ['sender_id', 'recipient_id', 'points', 'message'];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
