<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Booking extends Model
{
    use HasFactory;

    // สถานะที่ลูกค้าแก้ไขการจอง (ย้ายวัน/เปลี่ยนจุดรับ) ได้
    public const MODIFIABLE_STATUSES = ['pending', 'confirmed'];

    protected $fillable = [
        'booking_ref', 'user_id', 'schedule_id', 'pickup_region', 'pickup_point_id', 'status',
        'is_group', 'group_name', 'group_notes',
        'qr_code', 'share_token', 'checked_in', 'checked_in_at',
        'total_amount', 'selected_addons', 'addons_total', 'paid_amount', 'payment_method',
        'payment_type', 'installment_count', 'installment_interval_days',
        'deposit_amount', 'balance_amount', 'balance_due_at', 'balance_paid_at',
        'balance_payment_ref', 'balance_slip_path', 'balance_transfer_datetime',
        'balance_slip_ocr_status', 'balance_slip_ocr_result',
        'payment_ref', 'paid_at', 'slip_path', 'transfer_datetime',
        'slip_ocr_status', 'slip_ocr_result',
        'cancellation_reason', 'cancelled_at',
        'refund_status', 'refund_amount', 'refunded_at',
        'promotion_id', 'promotion_code', 'discount_amount',
        'is_join_trip',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'addons_total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refund_amount' => 'decimal:2',
            'refunded_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'is_group' => 'boolean',
            'checked_in' => 'boolean',
            'is_join_trip' => 'boolean',
            'installment_count' => 'integer',
            'installment_interval_days' => 'integer',
            'transfer_datetime' => 'datetime',
            'selected_addons' => 'array',
            'deposit_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
            'balance_due_at' => 'datetime',
            'balance_paid_at' => 'datetime',
            'balance_transfer_datetime' => 'datetime',
            'balance_slip_ocr_result' => 'array',
            'slip_ocr_result' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function pickupPoint(): BelongsTo
    {
        return $this->belongsTo(SchedulePickupPoint::class, 'pickup_point_id');
    }

    public function seats(): HasMany
    {
        return $this->hasMany(BookingSeat::class);
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(BookingPassenger::class);
    }

    public function installmentPayments(): HasMany
    {
        return $this->hasMany(InstallmentPayment::class)->orderBy('installment_no');
    }

    public function smsLogs(): HasMany
    {
        return $this->hasMany(SmsLog::class);
    }

    public function staffReviews(): HasMany
    {
        return $this->hasMany(StaffReview::class);
    }

    public static function generateRef(): string
    {
        $date = now()->format('Ymd');
        $last = static::whereDate('created_at', today())
            ->orderByDesc('id')
            ->first();

        $seq = $last ? ((int) substr($last->booking_ref, -4)) + 1 : 1;

        return sprintf('LLK-%s-%04d', $date, $seq);
    }

    public static function generateQrCode(): string
    {
        return 'QR-'.strtoupper(Str::random(16));
    }

    /**
     * คืนค่า share token สำหรับลิงก์ติดตามรถแบบสาธารณะ สร้างใหม่ถ้ายังไม่มี
     */
    public function ensureShareToken(): string
    {
        if (empty($this->share_token)) {
            do {
                $token = Str::lower(Str::random(12));
            } while (static::where('share_token', $token)->exists());

            $this->forceFill(['share_token' => $token])->save();
        }

        return $this->share_token;
    }

    public function shareUrl(): string
    {
        return url('/track/'.$this->ensureShareToken());
    }

    /**
     * กำหนดเส้นตายสำหรับการแก้ไขการจอง — ก่อนวันเดินทาง 1 วัน (สิ้นสุดปลายวัน)
     */
    public function modificationDeadline(): ?Carbon
    {
        $schedule = $this->relationLoaded('schedule') ? $this->schedule : $this->schedule()->first();

        return $schedule?->departure_date?->copy()->subDay()->endOfDay();
    }

    /**
     * ลูกค้าแก้ไขการจองได้หรือไม่ — สถานะต้อง active และยังไม่เลยเส้นตาย
     */
    public function canBeModified(): bool
    {
        if (! in_array($this->status, self::MODIFIABLE_STATUSES, true)) {
            return false;
        }

        $deadline = $this->modificationDeadline();

        return $deadline !== null && now()->lte($deadline);
    }
}
