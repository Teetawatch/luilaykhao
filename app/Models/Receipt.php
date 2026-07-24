<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Receipt extends Model
{
    protected $fillable = [
        'booking_id', 'receipt_no', 'verify_token', 'kind',
        'amount', 'currency', 'status', 'snapshot', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'snapshot' => 'array',
            'issued_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** RC-YYYYMM-XXXX โดย XXXX ไล่ตามลำดับในเดือนนั้น */
    public static function generateNumber(): string
    {
        $prefix = 'RC-'.now('Asia/Bangkok')->format('Ym').'-';

        $last = static::where('receipt_no', 'like', $prefix.'%')
            ->orderByDesc('receipt_no')
            ->value('receipt_no');

        $seq = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public static function generateToken(): string
    {
        do {
            $token = Str::lower(Str::random(20));
        } while (static::where('verify_token', $token)->exists());

        return $token;
    }
}
