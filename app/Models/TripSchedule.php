<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TripSchedule extends Model
{
    use HasFactory;

    public const ACTIVE_BOOKING_STATUSES = ['pending', 'confirmed'];

    public const REVIEW_AVAILABLE_TIMEZONE = 'Asia/Bangkok';

    protected $table = 'trip_schedules';

    protected $fillable = [
        'trip_id', 'departure_date', 'departs_at', 'return_date',
        'total_seats', 'booked_seats', 'transport_type',
        'vehicle_id', 'status', 'price_override',
        'installment_enabled', 'installment_count', 'installment_interval_days',
        'deposit_enabled', 'deposit_type', 'deposit_amount', 'deposit_percent',
        'join_trip_enabled', 'join_trip_price',
        'is_charter', 'photo_token',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'departs_at' => 'datetime',
            'return_date' => 'date',
            'total_seats' => 'integer',
            'booked_seats' => 'integer',
            'price_override' => 'decimal:2',
            'installment_enabled' => 'boolean',
            'installment_count' => 'integer',
            'installment_interval_days' => 'integer',
            'deposit_enabled' => 'boolean',
            'deposit_amount' => 'decimal:2',
            'deposit_percent' => 'integer',
            'join_trip_enabled' => 'boolean',
            'join_trip_price' => 'decimal:2',
            'is_charter' => 'boolean',
        ];
    }

    /**
     * วัน-เวลาออกเดินทางจริง — ใช้ departs_at ถ้ากำหนดไว้ (เช่น รถออกคืนก่อนวันทริป)
     * ไม่งั้น fallback เป็นต้นวันของ departure_date
     */
    public function effectiveDepartsAt(): ?Carbon
    {
        if ($this->departs_at) {
            return $this->departs_at->copy();
        }

        return $this->departure_date?->copy()->startOfDay();
    }

    /**
     * วันที่ของการออกเดินทางจริง (ไม่รวมเวลา) — ฐานคำนวณเส้นตายแก้ไข/เลื่อนการจอง
     */
    public function effectiveDepartureDate(): ?Carbon
    {
        return $this->effectiveDepartsAt()?->startOfDay();
    }

    /**
     * ข้อความวันเดินทางภาษาไทย เช่น "12 มิถุนายน 2026 เวลา 23:30 น."
     * ถ้าไม่ได้กำหนดเวลาออกรถ จะแสดงเฉพาะวันทริป
     */
    public function departureLabelThai(): string
    {
        if ($this->departs_at) {
            return $this->departs_at->locale('th')->isoFormat('D MMMM YYYY').' เวลา '.$this->departs_at->format('H:i').' น.';
        }

        return $this->departure_date?->locale('th')->isoFormat('D MMMM YYYY') ?? '-';
    }

    /**
     * ข้อความวันเดินทางแบบสั้น เช่น "12/06/2026 23:30 น." — ใช้ใน SMS
     */
    public function departureLabelShort(): string
    {
        if ($this->departs_at) {
            return $this->departs_at->format('d/m/Y H:i').' น.';
        }

        return $this->departure_date?->format('d/m/Y') ?? '-';
    }

    /**
     * Scope: รอบที่ "ออกเดินทางจริง" ตรงกับวันที่กำหนด — เทียบ departs_at
     * ถ้ามี ไม่งั้นเทียบ departure_date (รองรับรอบที่รถออกคืนก่อนวันทริป)
     */
    public function scopeDepartingOn($query, \DateTimeInterface|string $date)
    {
        $dateString = $date instanceof \DateTimeInterface
            ? Carbon::instance($date)->toDateString()
            : Carbon::parse($date)->toDateString();

        return $query->where(function ($query) use ($dateString) {
            $query->whereDate('departs_at', $dateString)
                ->orWhere(function ($query) use ($dateString) {
                    $query->whereNull('departs_at')
                        ->whereDate('departure_date', $dateString);
                });
        });
    }

    /**
     * Resolve the deposit amount for a given booking total.
     * For 'amount' type, the configured value is per-person and is multiplied by passengerCount.
     * Returns null when deposit is not enabled or amount cannot be determined.
     */
    public function resolveDepositAmount(float $totalAmount, int $passengerCount = 1): ?float
    {
        if (! $this->deposit_enabled) {
            return null;
        }

        if ($this->deposit_type === 'percent' && $this->deposit_percent) {
            $deposit = round($totalAmount * ((int) $this->deposit_percent) / 100, 2);
        } elseif ($this->deposit_type === 'amount' && $this->deposit_amount) {
            $deposit = round((float) $this->deposit_amount * max(1, $passengerCount), 2);
        } else {
            return null;
        }

        // Cap deposit at total amount, ensure positive
        $deposit = max(0.0, min($deposit, $totalAmount));

        return $deposit > 0 ? $deposit : null;
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'schedule_id');
    }

    public function bookingSeats(): HasMany
    {
        return $this->hasMany(BookingSeat::class, 'schedule_id');
    }

    public function pickupPoints(): HasMany
    {
        return $this->hasMany(SchedulePickupPoint::class, 'schedule_id')->orderBy('sort_order');
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'schedule_staff_assignments', 'schedule_id', 'user_id')
            ->withPivot(['assigned_by', 'created_at'])
            ->withTimestamps();
    }

    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(WaitlistEntry::class, 'schedule_id');
    }

    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(SchedulePhoto::class, 'schedule_photo', 'schedule_id', 'photo_id')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('schedule_photos.id');
    }

    /**
     * Generate (or rotate) the public photo-album token. Pass $rotate to force a new one.
     */
    public function ensurePhotoToken(bool $rotate = false): string
    {
        if ($rotate || empty($this->photo_token)) {
            do {
                $token = Str::lower(Str::random(12));
            } while (static::where('photo_token', $token)->exists());

            $this->forceFill(['photo_token' => $token])->save();
        }

        return $this->photo_token;
    }

    public function revokePhotoToken(): void
    {
        $this->forceFill(['photo_token' => null])->save();
    }

    public function photoAlbumUrl(): ?string
    {
        return $this->photo_token ? url('/album/'.$this->photo_token) : null;
    }

    public function getAvailableSeatsAttribute(): int
    {
        return max(0, (int) $this->total_seats - (int) $this->booked_seats);
    }

    public function getEffectivePriceAttribute(): float
    {
        return $this->price_override ?? $this->trip->price_per_person;
    }

    /**
     * Resolve the vehicle's seat layout, falling back to a generated 4-column
     * grid when no custom layout is configured. Returns the raw layout (seats
     * carry id/row/column/label but no live status) so callers can overlay
     * their own per-seat data — booking availability or staff occupancy.
     */
    public function resolveSeatLayout(): array
    {
        $layout = $this->vehicle?->seat_layout;
        if ($layout && isset($layout['seats'])) {
            return $layout;
        }

        $total = $this->total_seats ?: 10;
        $cols = ['A', 'B', 'C', 'D'];
        $numCols = 4;
        $numRows = (int) ceil($total / $numCols);

        $seats = [];
        for ($i = 0; $i < $total; $i++) {
            $row = (int) floor($i / $numCols) + 1;
            $colIdx = $i % $numCols;
            $seatId = $cols[$colIdx].$row;
            $seats[] = [
                'id' => $seatId,
                'row' => $row,
                'column' => $colIdx + 1,
                'label' => $seatId,
            ];
        }

        return [
            'rows' => $numRows,
            'columns' => array_slice($cols, 0, $numCols),
            'seats' => $seats,
            'front_label' => 'หน้ารถ',
            'rear_label' => 'หลังรถ',
            'show_driver' => true,
            'driver_icon' => 'directions_car',
        ];
    }

    public function reviewAvailableAt(): CarbonImmutable
    {
        $reviewDate = $this->return_date ?? $this->departure_date;

        return CarbonImmutable::parse($reviewDate->toDateString(), self::REVIEW_AVAILABLE_TIMEZONE)
            ->setTime(20, 0);
    }

    public function isReviewAvailable(): bool
    {
        return now(self::REVIEW_AVAILABLE_TIMEZONE)->greaterThanOrEqualTo($this->reviewAvailableAt());
    }

    /**
     * Recalculate and sync the booked_seats counter from actual bookings.
     */
    public function syncBookedSeats(): int
    {
        $count = BookingPassenger::whereHas('booking', function ($q) {
            $q->where('schedule_id', $this->id)
                ->whereIn('status', self::ACTIVE_BOOKING_STATUSES)
                ->where('is_join_trip', false);
        })->count();

        $this->update(['booked_seats' => $count]);
        $this->booked_seats = $count;

        return $count;
    }
}
