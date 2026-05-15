<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\InstallmentPayment;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_ref', 'user_id', 'schedule_id', 'pickup_region', 'pickup_point_id', 'status',
        'is_group', 'group_name', 'group_notes',
        'qr_code', 'checked_in', 'checked_in_at',
        'total_amount', 'selected_addons', 'addons_total', 'paid_amount', 'payment_method',
        'payment_type', 'installment_count', 'installment_interval_days',
        'payment_ref', 'paid_at', 'slip_path', 'transfer_datetime',
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
        return 'QR-' . strtoupper(\Illuminate\Support\Str::random(16));
    }
}
