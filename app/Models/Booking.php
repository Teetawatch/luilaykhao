<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Booking extends Model
{
    use HasFactory;

    // สถานะที่ลูกค้าแก้ไขการจอง (ย้ายวัน/เปลี่ยนจุดรับ) ได้
    public const MODIFIABLE_STATUSES = ['pending', 'confirmed'];

    // สถานะจุดรับแบบ custom ที่ลูกค้าปักหมุดเอง รอแอดมินยืนยัน
    public const CUSTOM_PICKUP_PENDING = 'pending';

    public const CUSTOM_PICKUP_APPROVED = 'approved';

    public const CUSTOM_PICKUP_REJECTED = 'rejected';

    // เปลี่ยนวันเดินทางได้ก่อนเดินทางอย่างน้อยกี่วัน
    public const RESCHEDULE_LEAD_DAYS = 20;

    // การจองสถานะ pending ที่ยังไม่ชำระเงินจะถูกยกเลิกอัตโนมัติหลังกี่นาที เพื่อคืนที่นั่ง
    public const PENDING_TTL_MINUTES = 10;

    // "ล็อกที่นั่งไว้ก่อน" ที่แอดมินกันไว้ให้ลูกค้า — นานกี่วันถ้าแอดมินไม่ระบุเอง
    // และเพดานที่ยอมให้ล็อกได้ (กันที่นั่งจมโดยไม่มีใครกลับมาดู)
    public const HOLD_DEFAULT_DAYS = 3;

    public const HOLD_MAX_DAYS = 30;

    protected $fillable = [
        'booking_ref', 'user_id', 'schedule_id', 'pickup_region', 'pickup_point_id', 'status',
        'vehicle_option_id', 'vehicle_option_label', 'vehicle_option_adjustment',
        'custom_pickup_label', 'custom_pickup_lat', 'custom_pickup_lng', 'custom_pickup_note',
        'custom_pickup_status', 'custom_pickup_price', 'custom_pickup_reject_reason', 'custom_pickup_resolved_at',
        'is_group', 'group_name', 'group_notes',
        'qr_code', 'share_token', 'payment_token', 'birthdate_token', 'passport_token', 'checked_in', 'checked_in_at',
        'total_amount', 'selected_addons', 'addons_total', 'selected_rentals', 'rentals_total', 'paid_amount', 'payment_method',
        'payment_type', 'installment_count', 'installment_interval_days',
        'deposit_amount', 'balance_amount', 'balance_due_at', 'balance_paid_at',
        'balance_payment_ref', 'balance_slip_path', 'balance_transfer_datetime',
        'balance_slip_ocr_status', 'balance_slip_ocr_result',
        'payment_ref', 'paid_at', 'slip_path', 'transfer_datetime',
        'slip_ocr_status', 'slip_ocr_result',
        'cancellation_reason', 'cancelled_at', 'rescheduled_at',
        'was_auto_expired', 'winback_sent_at',
        'hold_until', 'hold_note', 'hold_by_id',
        'refund_status', 'refund_amount', 'refunded_at', 'refund_slip_path',
        'promotion_id', 'promotion_code', 'discount_amount',
        'is_join_trip', 'flexi_surcharge',
        'is_gift', 'gift_code', 'gift_from_name', 'gift_message',
        'gifted_by_user_id', 'gift_claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'addons_total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'flexi_surcharge' => 'decimal:2',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'rescheduled_at' => 'datetime',
            'was_auto_expired' => 'boolean',
            'winback_sent_at' => 'datetime',
            'hold_until' => 'datetime',
            'refund_amount' => 'decimal:2',
            'refunded_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'is_group' => 'boolean',
            'checked_in' => 'boolean',
            'is_join_trip' => 'boolean',
            'is_gift' => 'boolean',
            'gift_claimed_at' => 'datetime',
            'installment_count' => 'integer',
            'installment_interval_days' => 'integer',
            'transfer_datetime' => 'datetime',
            'selected_addons' => 'array',
            'selected_rentals' => 'array',
            'rentals_total' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
            'balance_due_at' => 'datetime',
            'balance_paid_at' => 'datetime',
            'balance_transfer_datetime' => 'datetime',
            'balance_slip_ocr_result' => 'array',
            'slip_ocr_result' => 'array',
            'custom_pickup_lat' => 'float',
            'custom_pickup_lng' => 'float',
            'custom_pickup_price' => 'decimal:2',
            'vehicle_option_adjustment' => 'decimal:2',
            'custom_pickup_resolved_at' => 'datetime',
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

    /**
     * รถที่ลูกค้าเลือกตอนจอง (รอบที่วิ่งหลายแบบ) — อาจถูกลบทิ้งภายหลัง
     * ชื่อและส่วนต่างราคาที่ใช้จริงอ่านจากคอลัมน์สำเนาบนใบจองเสมอ
     */
    public function vehicleOption(): BelongsTo
    {
        return $this->belongsTo(ScheduleVehicleOption::class, 'vehicle_option_id');
    }

    /**
     * แอดมินที่กดล็อกที่นั่งใบนี้ไว้ให้ลูกค้า
     */
    public function holdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hold_by_id');
    }

    /**
     * ที่นั่งถูกทีมงานกันไว้ให้ ยังไม่ถึงเวลาปล่อย — ระหว่างนี้ ExpirePendingBookingsJob
     * จะไม่แตะใบนี้ และหน้าชำระเงินก็ไม่ต้องนับถอยหลังสิบนาทีของตัวเอง
     */
    public function isOnHold(): bool
    {
        return $this->status === 'pending'
            && $this->hold_until !== null
            && $this->hold_until->isFuture();
    }

    /**
     * เส้นตายเริ่มต้นของการล็อกที่นั่ง: HOLD_DEFAULT_DAYS วันนับจากนี้ แต่ไม่เลยเวลารถออก
     */
    public static function defaultHoldUntil(?TripSchedule $schedule = null): Carbon
    {
        return self::capHoldUntil(now()->addDays(self::HOLD_DEFAULT_DAYS), $schedule);
    }

    /**
     * ตัดเส้นตายล็อกที่นั่งไม่ให้เลยเวลาออกเดินทาง (ล็อกข้ามวันเดินทางไม่มีความหมาย)
     * และไม่ให้สั้นกว่าหนึ่งชั่วโมง เผื่อรอบที่กำลังจะออกเดินทางอยู่แล้ว
     */
    public static function capHoldUntil(Carbon|\DateTimeInterface|string $until, ?TripSchedule $schedule = null): Carbon
    {
        $until = $until instanceof Carbon ? $until->copy() : Carbon::parse($until);
        $departsAt = $schedule?->effectiveDepartsAt();

        // departs_at เก็บเวลานาฬิกาไทยไว้ในคอลัมน์ที่ระบบมองเป็น UTC — ต้องอ่านกลับ
        // เป็นเวลาไทยก่อนถึงจะเทียบกับ hold_until ที่เป็นเวลาจริงได้
        if ($departsAt) {
            $departureInstant = Carbon::parse($departsAt->format('Y-m-d H:i:s'), 'Asia/Bangkok')->utc();
            if ($departureInstant->lt($until)) {
                $until = $departureInstant;
            }
        }

        return $until->greaterThan(now()) ? $until : now()->addHour();
    }

    /**
     * ผู้ซื้อของขวัญคนเดิม — เซ็ตหลังผู้รับกดรับ (ก่อนรับ ผู้ซื้อคือ user_id)
     */
    public function giftedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gifted_by_user_id');
    }

    /**
     * user id ของ "ผู้ให้ของขวัญ" ณ ปัจจุบัน — ก่อนรับคือเจ้าของการจอง (ผู้ซื้อ)
     * หลังรับ ownership ย้ายไปผู้รับแล้ว ผู้ให้อยู่ที่ gifted_by_user_id
     */
    public function giftGiverUserId(): ?int
    {
        if (! $this->is_gift) {
            return null;
        }

        return $this->gift_claimed_at !== null
            ? $this->gifted_by_user_id
            : $this->user_id;
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

    /** สถานะแจก/รับคืนอุปกรณ์เช่า — ผูกกับ selected_rentals ด้วยชื่อรายการ */
    public function rentalHandouts(): HasMany
    {
        return $this->hasMany(BookingRentalHandout::class);
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(BookingPassenger::class);
    }

    /** ไฟล์เอกสารที่ลูกค้าแนบมาตามที่ทริปขอ (ผูกรายผู้เดินทาง) */
    public function documents(): HasMany
    {
        return $this->hasMany(BookingDocument::class);
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

    public function splitShares(): HasMany
    {
        return $this->hasMany(BookingSplitShare::class)->orderBy('id');
    }

    /** รายการชำระเงินผ่านเกตเวย์ (Beam) — หลายแถวได้ เพราะจ่ายกันคนละยอดคนละเวลา. */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('id');
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

    /**
     * รูปแบบเลขที่จอง: LLK-YYYYMMDD-NNNN โดย NNNN นับ 1 ใหม่ทุกวัน
     */
    private const REF_PATTERN = '/^LLK-\d{8}-(\d{4})$/';

    public static function generateRef(): string
    {
        $date = now()->format('Ymd');

        // อ่านลำดับจากเลขที่จองที่ตรงรูปแบบเท่านั้น — เลขที่ผิดรูปแบบต้องไม่ลาก
        // ให้ทั้งวันออกเลขซ้ำหรือพัง (substr ดิบ ๆ เคยตีคำว่า "9e18" เป็น
        // ทศนิยมยกกำลังจนแคสต์เป็น int ไม่ได้)
        $seq = static::whereDate('created_at', today())
            ->pluck('booking_ref')
            ->map(fn (?string $ref) => preg_match(self::REF_PATTERN, (string) $ref, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return sprintf('LLK-%s-%04d', $date, $seq + 1);
    }

    public static function generateQrCode(): string
    {
        return 'QR-'.strtoupper(Str::random(16));
    }

    /**
     * โค้ดของขวัญแบบอ่านง่าย 8 ตัว — ตัดอักขระที่สับสนง่าย (0/O, 1/I/L)
     * เพราะผู้รับต้องพิมพ์ตามที่เห็นจากแชท/การ์ดอวยพร
     */
    public static function generateGiftCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::where('gift_code', $code)->exists());

        return $code;
    }

    /**
     * ลิงก์เว็บสาธารณะสำหรับหน้า reveal ของขวัญ — ผู้ให้ส่งลิงก์นี้ให้ผู้รับ
     * (กดแล้วเปิดหน้าเปิดของขวัญบนเว็บ + ปุ่มเปิดในแอปเพื่อกดรับ)
     */
    public function giftUrl(): ?string
    {
        if (! $this->is_gift || empty($this->gift_code)) {
            return null;
        }

        return url('/gift/'.$this->gift_code);
    }

    /**
     * ชำระเงินครบทั้งการจองแล้วหรือยัง — เงื่อนไขก่อนให้ผู้รับกดรับของขวัญ
     * (ห้ามส่งของขวัญที่ยังมียอดค้างไปให้ผู้รับแบกภาระ)
     */
    public function isFullyPaid(): bool
    {
        if ($this->status !== 'confirmed') {
            return false;
        }

        return match ($this->payment_type ?? 'full') {
            'deposit' => $this->balance_paid_at !== null,
            'installment' => ! $this->installmentPayments()->where('status', '!=', 'paid')->exists(),
            default => true,
        };
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
     * คืนค่า token สำหรับลิงก์กรอกเอกสารเดินทางของผู้เดินทางทั้งการจอง
     * ใช้กับการจองทริปต่างประเทศที่เข้ามาจากช่องทางที่ยังถามพาสปอร์ตไม่ได้
     */
    public function ensurePassportToken(): string
    {
        if (empty($this->passport_token)) {
            do {
                $token = Str::lower(Str::random(16));
            } while (static::where('passport_token', $token)->exists());

            $this->forceFill(['passport_token' => $token])->save();
        }

        return $this->passport_token;
    }

    public function passportUrl(): string
    {
        return url('/booking-passport/'.$this->ensurePassportToken());
    }

    /**
     * ผู้เดินทางที่ยังขาดเอกสารเดินทาง — ว่างเสมอสำหรับทริปในประเทศ
     *
     * ครบ = มีทั้งชื่อภาษาอังกฤษ เลขพาสปอร์ต และวันหมดอายุ ขาดอย่างใดอย่างหนึ่ง
     * ก็ออกตั๋วไม่ได้ จึงนับว่ายังไม่ครบทั้งคน
     */
    public function passengersMissingPassport(): Collection
    {
        if (! $this->schedule?->trip?->isInternational()) {
            return collect();
        }

        return $this->passengers->filter(fn ($passenger) => blank($passenger->name_en)
            || blank($passenger->passport_no)
            || blank($passenger->passport_expires_at));
    }

    public function needsPassportInfo(): bool
    {
        return $this->passengersMissingPassport()->isNotEmpty();
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
