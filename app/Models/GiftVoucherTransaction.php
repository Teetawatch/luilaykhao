<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftVoucherTransaction extends Model
{
    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_REDEEM = 'redeem';

    public const TYPE_RESTORE = 'restore';

    public const TYPE_ADJUST = 'adjust';

    protected $fillable = [
        'gift_voucher_id', 'booking_id', 'type', 'amount', 'balance_after', 'note', 'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(GiftVoucher::class, 'gift_voucher_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
