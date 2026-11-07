<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CoinTransaction extends Model
{
    /** ได้จากการรีวิว (อ้างอิงใบจอง — ตัวกันได้ซ้ำต่อผู้รีวิวต่อใบจอง). */
    public const TYPE_REVIEW = 'review';

    /** ถอนคืนเพราะทีมงานซ่อน/ลบรีวิว. */
    public const TYPE_REVIEW_REVOKE = 'review_revoke';

    /** ใช้หักค่าทริปตอนจอง. */
    public const TYPE_SPEND = 'spend';

    /** คืนกลับเมื่อใบจองที่ใช้ Coin ถูกยกเลิก/คืนเงิน. */
    public const TYPE_RESTORE = 'restore';

    /** ทีมงานปรับยอดเอง. */
    public const TYPE_ADJUST = 'adjust';

    protected $fillable = [
        'user_id', 'type', 'coins', 'balance_after', 'description',
        'reference_type', 'reference_id',
    ];

    protected function casts(): array
    {
        return [
            'coins' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
