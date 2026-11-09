<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ทริปที่ลูกค้ากดหัวใจไว้ — หนึ่งแถวต่อ (ผู้ใช้, ทริป) ดู WishlistService
 */
class WishlistItem extends Model
{
    protected $fillable = ['user_id', 'trip_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
