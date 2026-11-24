<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** คูปองที่ลูกค้าเก็บไว้ — ดู PromotionController::saved() */
class SavedPromotion extends Model
{
    protected $fillable = ['user_id', 'promotion_id', 'claimed_at'];

    /** มีค่า = แถวนี้ถือใบจากโควตาของคูปองแจกจำนวนจำกัดแล้ว */
    protected $casts = ['claimed_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
