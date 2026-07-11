<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ข้อเสนอ Flexi-Price (Go Together) ของรอบเดินทางหนึ่ง — ผู้จัดขอเก็บส่วนต่าง
 * ค่ารถท่านละ surcharge_per_person เพื่อให้รอบที่คนไม่ครบยังออกได้ตามกำหนด
 * ยืนยันเมื่อผู้จองทุกรายกด "ยอมรับ"
 */
class FlexiDepartureOffer extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'schedule_id', 'surcharge_per_person', 'reason',
        'status', 'respond_by', 'created_by', 'confirmed_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'surcharge_per_person' => 'decimal:2',
            'respond_by' => 'datetime',
            'confirmed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(FlexiDepartureConsent::class, 'offer_id');
    }

    /** ยังเปิดให้ตอบรับอยู่หรือไม่ (pending และยังไม่หมดเวลา) */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->respond_by->isFuture();
    }
}
