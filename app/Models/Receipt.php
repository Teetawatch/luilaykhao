<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Receipt extends Model
{
    protected $fillable = [
        'booking_id', 'parent_id', 'passenger_id', 'receipt_no', 'verify_token', 'kind', 'holder',
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

    /** ใบรวมที่ใบแยกรายบุคคลใบนี้แตกออกมา (null = ตัวมันเองคือใบรวม) */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'parent_id');
    }

    /** ใบแยกรายบุคคลของใบรวมนี้ เรียงตามลำดับผู้เดินทาง */
    public function personalReceipts(): HasMany
    {
        return $this->hasMany(Receipt::class, 'parent_id')->orderBy('id');
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(BookingPassenger::class, 'passenger_id');
    }

    public function isPersonal(): bool
    {
        return $this->parent_id !== null;
    }

    public static function holderFor(?BookingPassenger $passenger): string
    {
        return $passenger ? 'passenger:'.$passenger->id : 'booking';
    }

    /**
     * RC-YYYYMM-XXXX โดย XXXX ไล่ตามลำดับในเดือนนั้น
     *
     * นับเฉพาะใบรวม — ใบแยกรายบุคคลใช้เลขใบแม่ต่อท้าย (RC-…-0012-2) ถ้านับ
     * ด้วย afterLast('-') จะได้ "2" แล้วเลขใบถัดไปย้อนกลับไปชนของเดิม
     */
    public static function generateNumber(): string
    {
        $prefix = 'RC-'.now('Asia/Bangkok')->format('Ym').'-';

        $last = static::whereNull('parent_id')
            ->where('receipt_no', 'like', $prefix.'%')
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
