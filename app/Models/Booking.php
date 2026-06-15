<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Booking extends Model
{
    use HasFactory;

    // สถานะที่ลูกค้าแก้ไขการจอง (ย้ายวัน/เปลี่ยนจุดรับ) ได้
    public const MODIFIABLE_STATUSES = ['pending', 'confirmed'];

    // เปลี่ยนวันเดินทางได้ก่อนเดินทางอย่างน้อยกี่วัน
    public const RESCHEDULE_LEAD_DAYS = 20;

    // การจองสถานะ pending ที่ยังไม่ชำระเงินจะถูกยกเลิกอัตโนมัติหลังกี่นาที เพื่อคืนที่นั่ง
    public const PENDING_TTL_MINUTES = 10;

    protected $fillable = [
        'booking_ref', 'user_id', 'schedule_id', 'pickup_region', 'pickup_point_id', 'status',
        'is_group', 'group_name', 'group_notes',
        'qr_code', 'share_token', 'payment_token', 'birthdate_token', 'checked_in', 'checked_in_at',
        'total_amount', 'selected_addons', 'addons_total', 'paid_amount', 'payment_method',
        'payment_type', 'installment_count', 'installment_interval_days',
        'deposit_amount', 'balance_amount', 'balance_due_at', 'balance_paid_at',
        'balance_payment_ref', 'balance_slip_path', 'balance_transfer_datetime',
        'balance_slip_ocr_status', 'balance_slip_ocr_result',
        'payment_ref', 'paid_at', 'slip_path', 'transfer_datetime',
        'slip_ocr_status', 'slip_ocr_result',
        'cancellation_reason', 'cancelled_at', 'rescheduled_at',
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
            'rescheduled_at' => 'datetime',
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

    public function members(): HasMany
    {
        return $this->hasMany(BookingMember::class);
    }

    /**
     * user id ที่เข้าถึงการจองนี้ได้ผ่านแอป = เจ้าของ + สมาชิกที่รับคำเชิญแล้ว (active)
     *
     * @return list<int>
     */
    public function accessUserIds(): array
    {
        $ids = $this->members()
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->all();

        if ($this->user_id !== null) {
            $ids[] = $this->user_id;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * ผู้ใช้รายนี้เข้าถึงการจองนี้ได้หรือไม่ (เจ้าของหรือสมาชิก active)
     */
    public function isAccessibleByUser(int $userId): bool
    {
        if ($this->user_id === $userId) {
            return true;
        }

        return $this->members()
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->where('user_id', $userId)
            ->exists();
    }

    public function installmentPayments(): HasMany
    {
        return $this->hasMany(InstallmentPayment::class)->orderBy('installment_no');
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
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
     * คืนค่า token สำหรับลิงก์กรอกวันเกิดของผู้เดินทางทั้งการจอง (กรณีจองแทนเพื่อน)
     * สร้างใหม่ถ้ายังไม่มี
     */
    public function ensureBirthdateToken(): string
    {
        if (empty($this->birthdate_token)) {
            do {
                $token = Str::lower(Str::random(16));
            } while (static::where('birthdate_token', $token)->exists());

            $this->forceFill(['birthdate_token' => $token])->save();
        }

        return $this->birthdate_token;
    }

    public function birthdateUrl(): string
    {
        return url('/booking-birthdate/'.$this->ensureBirthdateToken());
    }

    /**
     * คืนค่า payment token สำหรับลิงก์ชำระค่างวดแบบสาธารณะ สร้างใหม่ถ้ายังไม่มี
     */
    public function ensurePaymentToken(): string
    {
        if (empty($this->payment_token)) {
            do {
                $token = Str::lower(Str::random(12));
            } while (static::where('payment_token', $token)->exists());

            $this->forceFill(['payment_token' => $token])->save();
        }

        return $this->payment_token;
    }

    public function payUrl(): string
    {
        return url('/pay/'.$this->ensurePaymentToken());
    }

    /**
     * กำหนดเส้นตายสำหรับการแก้ไขการจอง — ก่อนวันออกเดินทางจริง 1 วัน (สิ้นสุดปลายวัน)
     * ใช้ departs_at ถ้ารอบนั้นรถออกคืนก่อนวันทริป
     */
    public function modificationDeadline(): ?Carbon
    {
        $schedule = $this->relationLoaded('schedule') ? $this->schedule : $this->schedule()->first();

        return $schedule?->effectiveDepartureDate()?->subDay()->endOfDay();
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

    /**
     * เส้นตายการเปลี่ยนวันเดินทาง — ก่อนวันออกเดินทางจริงอย่างน้อย RESCHEDULE_LEAD_DAYS วัน
     */
    public function rescheduleDeadline(): ?Carbon
    {
        $schedule = $this->relationLoaded('schedule') ? $this->schedule : $this->schedule()->first();

        return $schedule?->effectiveDepartureDate()?->subDays(self::RESCHEDULE_LEAD_DAYS)->endOfDay();
    }

    /**
     * เปลี่ยนวันเดินทางได้หรือไม่ — สถานะ active, ยังไม่เคยเปลี่ยน (ครั้งเดียว),
     * และยังไม่เลยเส้นตาย 20 วันก่อนเดินทาง
     */
    public function canBeRescheduled(): bool
    {
        if (! in_array($this->status, self::MODIFIABLE_STATUSES, true)) {
            return false;
        }

        if ($this->rescheduled_at !== null) {
            return false;
        }

        $deadline = $this->rescheduleDeadline();

        return $deadline !== null && now()->lte($deadline);
    }
}
