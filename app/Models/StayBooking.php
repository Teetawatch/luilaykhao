<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ใบจองที่พัก — ดู App\Services\StayBookingService
 *
 * โหมดส่งคำขอ:  requested → awaiting_payment → (payment_review →) confirmed → completed
 *                         ↘ declined / expired
 * โหมดจองทันที:             awaiting_payment → (payment_review →) confirmed → completed
 * ยังไม่จ่าย → expired (หมดเวลา) · ยกเลิกได้ทุกขั้นก่อน completed → cancelled
 */
class StayBooking extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_PAYMENT_REVIEW = 'payment_review';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_AWAITING_PAYMENT, self::STATUS_PAYMENT_REVIEW,
        self::STATUS_CONFIRMED, self::STATUS_COMPLETED, self::STATUS_DECLINED,
        self::STATUS_EXPIRED, self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_REQUESTED => 'รอยืนยันห้องว่าง',
        self::STATUS_AWAITING_PAYMENT => 'รอชำระเงิน',
        self::STATUS_PAYMENT_REVIEW => 'กำลังตรวจสลิป',
        self::STATUS_CONFIRMED => 'ยืนยันแล้ว',
        self::STATUS_COMPLETED => 'เข้าพักแล้ว',
        self::STATUS_DECLINED => 'ห้องไม่ว่าง',
        self::STATUS_EXPIRED => 'หมดเวลา',
        self::STATUS_CANCELLED => 'ยกเลิกแล้ว',
    ];

    /** สถานะที่กันห้องไว้ — นับลบออกจากห้องว่าง */
    public const HOLDING_STATUSES = [
        self::STATUS_AWAITING_PAYMENT, self::STATUS_PAYMENT_REVIEW,
        self::STATUS_CONFIRMED, self::STATUS_COMPLETED,
    ];

    public const REFUND_PENDING = 'pending';

    public const REFUND_REFUNDED = 'refunded';

    protected $fillable = [
        'ref', 'user_id', 'stay_id', 'room_type_id', 'trip_booking_id', 'room_name',
        'check_in', 'check_out', 'nights', 'units', 'guests',
        'guest_name', 'guest_phone', 'guest_email', 'note', 'status', 'booking_mode',
        'nightly_prices', 'room_amount', 'extra_guest_amount', 'experiences', 'experiences_amount',
        'total_amount', 'commission_percent', 'commission_amount', 'host_amount', 'cancellation_policy',
        'responded_at', 'responded_by', 'decline_reason',
        'payment_due_at', 'slip_path', 'slip_ocr_status', 'slip_ocr_result', 'payment_ref', 'payment_method',
        'paid_at', 'paid_amount', 'payment_reviewed_by_id', 'payment_note',
        'cancelled_at', 'cancelled_by', 'cancel_reason', 'refund_amount', 'refund_status',
        'refund_account', 'refunded_at', 'refund_note',
        'completed_at', 'payout_eligible_at', 'payout_id', 'review_invited_at', 'admin_note',
    ];

    protected $hidden = ['refund_account', 'slip_path', 'slip_ocr_result', 'admin_note'];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'nights' => 'integer',
            'units' => 'integer',
            'guests' => 'integer',
            'nightly_prices' => 'array',
            'room_amount' => 'decimal:2',
            'extra_guest_amount' => 'decimal:2',
            'experiences' => 'array',
            'experiences_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'commission_percent' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'host_amount' => 'decimal:2',
            'responded_at' => 'datetime',
            'payment_due_at' => 'datetime',
            'slip_ocr_result' => 'array',
            'paid_at' => 'datetime',
            'paid_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'refund_amount' => 'decimal:2',
            'refund_account' => 'encrypted:array',
            'refunded_at' => 'datetime',
            'completed_at' => 'datetime',
            'payout_eligible_at' => 'datetime',
            'review_invited_at' => 'datetime',
        ];
    }

    /**
     * วันเข้า/ออกเก็บเป็น Y-m-d ล้วนทุกฐานข้อมูล — ห้องว่างและงานปิดใบเทียบวันที่
     * กับสตริง Y-m-d (SQLite ในเทสต์จะเก็บเวลาติดมาด้วยถ้าปล่อยให้ cast date ทำ)
     */
    protected function checkIn(): Attribute
    {
        return Attribute::make(set: fn ($value) => $value === null ? null : Carbon::parse($value)->toDateString());
    }

    protected function checkOut(): Attribute
    {
        return Attribute::make(set: fn ($value) => $value === null ? null : Carbon::parse($value)->toDateString());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(StayRoomType::class, 'room_type_id');
    }

    public function tripBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'trip_booking_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(StayPayout::class, 'payout_id');
    }

    /** ใบชำระผ่าน Beam ของใบจองนี้ (ทางสลิปไม่มีแถวที่นี่). */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(StayReview::class, 'stay_booking_id');
    }

    public function scopeHolding(Builder $query): Builder
    {
        return $query->whereIn('status', self::HOLDING_STATUSES);
    }

    public static function generateRef(): string
    {
        do {
            $ref = 'LLK-STAY-'.now('Asia/Bangkok')->format('ymd').'-'.strtoupper(Str::random(4));
        } while (self::where('ref', $ref)->exists());

        return $ref;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function canPay(): bool
    {
        return in_array($this->status, [self::STATUS_AWAITING_PAYMENT], true)
            && ($this->payment_due_at === null || $this->payment_due_at->isFuture());
    }

    public function canCancel(): bool
    {
        if (! in_array($this->status, [
            self::STATUS_REQUESTED, self::STATUS_AWAITING_PAYMENT, self::STATUS_PAYMENT_REVIEW, self::STATUS_CONFIRMED,
        ], true)) {
            return false;
        }

        // วันเข้าพักมาถึงแล้ว — ยกเลิกเองไม่ได้ ติดต่อทีมงาน
        return $this->check_in->toDateString() > now('Asia/Bangkok')->toDateString();
    }

    public function canReview(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /** ลิงก์หน้าใบจองบนเว็บ — ใช้ใน LINE/อีเมล */
    public function webUrl(): string
    {
        return url('/stays/bookings/'.$this->ref);
    }
}
