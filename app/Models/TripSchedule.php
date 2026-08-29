<?php

namespace App\Models;

use App\Jobs\NotifyTripCrewAssignedJob;
use App\Jobs\SendDriverAssignmentPushJob;
use App\Support\LoyaltyTier;
use App\Support\SiteSettings;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * พาหนะที่รับได้ — ค่านี้คือแหล่งเดียวที่ validation ทุกที่อ้างถึง
     * (คอลัมน์ในฐานข้อมูลเป็น VARCHAR แล้ว ไม่ได้บังคับค่าให้)
     */
    public const TRANSPORT_TYPES = ['van', 'boat', 'bus', 'flight'];

    public const TRANSPORT_FLIGHT = 'flight';

    public const REVIEW_AVAILABLE_TIMEZONE = 'Asia/Bangkok';

    /**
     * ระบบสถานะการันตีออกเดินทาง (Trip Status) — จำนวนที่นั่งที่จองแล้วเป็นตัวกำหนด
     * ว่ารอบนี้จะได้ออกเดินทางแน่นอนหรือยัง (สอดคล้องกับ MIN_SEATS ใน
     * SendUnderfilledTripWarningsJob):
     *   1-4  → waiting       🔴 ยังขาดเพื่อนร่วมทาง
     *   5-7  → almost_ready  🟡 ขาดอีกนิดเดียว รถตู้ออกชัวร์
     *   8+   → guaranteed    🟢 คอนเฟิร์มรถออกแน่นอน 100%
     *
     * ค่านี้เป็นค่าตั้งต้น — แอดมินปรับได้ที่หน้าตั้งค่าระบบ ใช้ผ่าน
     * [guaranteeMinSeats()] เสมอ อย่าอ่าน const ตรง ๆ
     */
    public const GUARANTEE_MIN_SEATS = 8;

    public const ALMOST_READY_MIN_SEATS = 5;

    public const STATUS_WAITING = 'waiting';

    public const STATUS_ALMOST_READY = 'almost_ready';

    public const STATUS_GUARANTEED = 'guaranteed';

    protected $table = 'trip_schedules';

    /**
     * Mirror the DB column default in-memory so a just-created schedule reports
     * 'open' even when the caller omits `status`. The observer that fires the
     * "new round" alert/broadcast reads `status` off the fresh instance.
     */
    protected $attributes = [
        'status' => 'open',
    ];

    protected $fillable = [
        'trip_id', 'departure_date', 'departs_at', 'return_date',
        'total_seats', 'booked_seats', 'transport_type',
        'vehicle_id', 'status', 'booking_opens_at', 'price_override',
        'flash_sale_enabled', 'flash_sale_starts_at', 'flash_sale_price', 'flash_sale_ends_at',
        // เลิกใช้แล้ว: แผนผ่อนชำระคิดจากวันเดินทางที่ PaymentQuote ไม่มีใครเขียนสามคอลัมน์นี้
        // อีกแล้ว เหลือไว้เพื่อการจองเก่าที่อ้างอิงค่าตอนนั้น
        'installment_enabled', 'installment_count', 'installment_interval_days',
        'deposit_enabled', 'deposit_type', 'deposit_amount', 'deposit_percent',
        'join_trip_enabled', 'join_trip_price', 'join_trip_seats', 'join_trip_booked_seats',
        'is_charter', 'photo_token', 'custom_route',
        // รอบที่บินไป — จุดนัดพบที่สนามบิน + ขาบิน + น้ำหนักกระเป๋า
        'meeting_point', 'meeting_map_url', 'meeting_time', 'baggage_allowance', 'flights',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'departs_at' => 'datetime',
            'booking_opens_at' => 'datetime',
            'return_date' => 'date',
            'total_seats' => 'integer',
            'booked_seats' => 'integer',
            'price_override' => 'decimal:2',
            'flash_sale_enabled' => 'boolean',
            'flash_sale_starts_at' => 'datetime',
            'flash_sale_price' => 'decimal:2',
            'flash_sale_ends_at' => 'datetime',
            'installment_enabled' => 'boolean',
            'installment_count' => 'integer',
            'installment_interval_days' => 'integer',
            'deposit_enabled' => 'boolean',
            'deposit_amount' => 'decimal:2',
            'deposit_percent' => 'integer',
            'join_trip_enabled' => 'boolean',
            'join_trip_price' => 'decimal:2',
            'join_trip_seats' => 'integer',
            'join_trip_booked_seats' => 'integer',
            'is_charter' => 'boolean',
            'driver_pin_cleared_at' => 'datetime',
            'rally_nudged_at' => 'datetime',
            'custom_route' => 'array',
            'flights' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Notify the vehicle's driver when a vehicle is assigned to this round
        // (drivers are linked via the vehicle, not the staff pivot, so the
        // staff-assignment push never reaches them).
        static::saved(function (self $schedule) {
            if ($schedule->wasChanged('vehicle_id') && $schedule->vehicle_id) {
                SendDriverAssignmentPushJob::dispatch(
                    $schedule->id,
                    (int) $schedule->vehicle_id,
                );

                // ลูกค้าก็ต้องรู้ด้วย — "ทะเบียนรถอะไร เบอร์คนขับ" คือคำถามยอดฮิต
                // ในห้องแชท และก่อนหน้านี้ไม่มีอะไรบอกเขาว่าข้อมูลมาแล้ว
                NotifyTripCrewAssignedJob::dispatch(
                    $schedule->id,
                    NotifyTripCrewAssignedJob::KIND_VEHICLE,
                );
            }
        });
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
            return ThaiDate::full($this->departs_at).' เวลา '.$this->departs_at->format('H:i').' น.';
        }

        return ThaiDate::full($this->departure_date);
    }

    /**
     * ช่วงวันเดินทางภาษาไทย เช่น "5 – 7 กันยายน 2569" — ใช้กับหน้าที่ลูกค้าอ่าน
     * แล้วต้องตอบตัวเองได้ว่า "รอบนี้คือวันไหนถึงวันไหน" ไม่ใช่แค่วันออกเดินทาง
     */
    public function dateRangeLabelThai(): string
    {
        return ThaiDate::range($this->departure_date, $this->return_date);
    }

    /**
     * ข้อความวันเดินทางแบบสั้น เช่น "12/06/2026 23:30 น." — ใช้ใน SMS
     */
    public function departureLabelShort(): string
    {
        if ($this->departs_at) {
            return ThaiDate::shortTime($this->departs_at).' น.';
        }

        return ThaiDate::short($this->departure_date);
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
     * Scope: รอบที่ "กำลังเดินทางอยู่" ในวันที่กำหนด — ตั้งแต่วันออก (หรือคืนก่อน
     * หน้าถ้า departs_at ตั้งไว้) จนถึงวันกลับ ต่างจาก departingOn ที่จับเฉพาะ
     * วันออกเดินทางวันแรก ทำให้ทริปหลายวันหลุดหายไปตั้งแต่วันที่สอง
     */
    public function scopeInProgressOn($query, \DateTimeInterface|string $date)
    {
        $dateString = $date instanceof \DateTimeInterface
            ? Carbon::instance($date)->toDateString()
            : Carbon::parse($date)->toDateString();

        return $query->where(function ($query) use ($dateString) {
            // เริ่มแล้ว
            $query->where(function ($q) use ($dateString) {
                $q->whereDate('departs_at', '<=', $dateString)
                    ->orWhere(function ($q2) use ($dateString) {
                        $q2->whereNull('departs_at')
                            ->whereDate('departure_date', '<=', $dateString);
                    });
            })
                // ยังไม่จบ
                ->where(function ($q) use ($dateString) {
                    $q->whereDate('return_date', '>=', $dateString)
                        ->orWhere(function ($q2) use ($dateString) {
                            $q2->whereNull('return_date')
                                ->whereDate('departure_date', '>=', $dateString);
                        });
                });
        });
    }

    /**
     * Resolve the deposit amount for a given booking total.
     * For 'amount' type, the configured value is per-person and is multiplied by passengerCount.
     * Returns null when deposit is not enabled or amount cannot be determined.
     */
    /**
     * เวลาที่ผู้ใช้คนนี้เริ่มจองรอบนี้ได้ — null = จองได้เลย
     *
     * สมาชิกระดับสูงได้เริ่มก่อนเวลาเปิดจองสาธารณะตามชั่วโมงของระดับตัวเอง
     */
    public function bookingOpensAtFor(?int $userId): ?Carbon
    {
        if (! $this->booking_opens_at) {
            return null;
        }

        $hours = (int) LoyaltyTier::perk(
            LoyaltyAccount::tierForUser($userId),
            'early_access_hours',
        );

        return $this->booking_opens_at->copy()->subHours($hours);
    }

    /** ผู้ใช้คนนี้จองรอบนี้ได้แล้วหรือยัง. */
    public function isBookableBy(?int $userId): bool
    {
        $opensAt = $this->bookingOpensAtFor($userId);

        return $opensAt === null || $opensAt->isPast();
    }

    /**
     * ยอดมัดจำของรอบนี้
     *
     * ระบุ $userId มาด้วยจะได้ส่วนลดมัดจำตามระดับสมาชิก — ลดเฉพาะ "ยอดที่ต้องวาง
     * ก่อน" ไม่ได้ลดราคาทริป ส่วนที่เหลือไปรวมกับยอดคงค้างตามเดิม
     */
    public function resolveDepositAmount(float $totalAmount, int $passengerCount = 1, ?int $userId = null): ?float
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

        $discountPercent = (int) LoyaltyTier::perk(
            LoyaltyAccount::tierForUser($userId),
            'deposit_discount_percent',
        );

        if ($discountPercent > 0) {
            $deposit = round($deposit * (100 - $discountPercent) / 100, 2);
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

    public function expenses(): HasMany
    {
        return $this->hasMany(ScheduleExpense::class, 'schedule_id')->orderBy('id');
    }

    public function pickupPoints(): HasMany
    {
        return $this->hasMany(SchedulePickupPoint::class, 'schedule_id')->orderBy('sort_order');
    }

    /**
     * จุดพิกัดของเส้นทางเดินรถที่แอดมินวาดเอง (กรองเฉพาะรายการที่พิกัดใช้ได้)
     * — มีตั้งแต่ 2 จุดขึ้นไปเมื่อไหร่ เส้นนี้ override เส้นจาก Google Directions
     *
     * @return array<int, array{lat: float, lng: float}>
     */
    public function customRoutePoints(): array
    {
        $points = [];
        foreach (is_array($this->custom_route) ? $this->custom_route : [] as $point) {
            $lat = isset($point['lat']) && is_numeric($point['lat']) ? (float) $point['lat'] : null;
            $lng = isset($point['lng']) && is_numeric($point['lng']) ? (float) $point['lng'] : null;
            if ($lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180) {
                $points[] = ['lat' => $lat, 'lng' => $lng];
            }
        }

        return $points;
    }

    public function itineraryItems(): HasMany
    {
        return $this->hasMany(ScheduleItineraryItem::class, 'schedule_id')
            ->orderByRaw('item_date is null')
            ->orderBy('item_date')
            ->orderByRaw('time is null')
            ->orderBy('time')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * ทุกคนที่เคยถูกมอบหมายให้รอบนี้ รวมคนที่ถูกปลดหลังจบทริปแล้ว — ใช้กับสิทธิ์
     * ที่อิงประวัติ (ห้องแชท, SOS, รายงานอุบัติเหตุ) และการนับผลงานของสตาฟ
     */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'schedule_staff_assignments', 'schedule_id', 'user_id')
            ->withPivot(['assigned_by', 'created_at', 'released_at'])
            ->withTimestamps();
    }

    /**
     * เฉพาะสตาฟที่ยังรับผิดชอบรอบนี้อยู่ — พอรอบจบ ReleaseEndedTripStaffJob จะปลด
     * ทุกคนออก ทำให้รอบเก่าไม่ค้างชื่อใครไว้ในหน้าจัดการ/ตารางงาน
     */
    public function activeStaff(): BelongsToMany
    {
        return $this->staff()->wherePivotNull('released_at');
    }

    public function releasedStaff(): BelongsToMany
    {
        return $this->staff()->wherePivotNotNull('released_at');
    }

    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(WaitlistEntry::class, 'schedule_id');
    }

    /**
     * เติม held_seats = ที่นั่งที่กันไว้ให้คนในคิวรอที่ได้รับสิทธิ์แล้วและยังไม่หมดเวลา
     * ใช้ subquery ตัวเดียวเพื่อไม่ให้หน้าที่โหลดหลายรอบพร้อมกันยิงคิวรอบละครั้ง
     * (ที่นั่งเหล่านี้ยังนับเป็น available_seats อยู่ แต่คนทั่วไปจองไม่ได้)
     */
    public function scopeWithHeldSeats(Builder $query): Builder
    {
        return $query->withSum(
            ['waitlistEntries as held_seats' => fn ($q) => $q
                ->where('status', 'offered')
                ->where('expires_at', '>', now())],
            'seat_count'
        );
    }

    /**
     * ที่นั่งที่ "จองได้จริง" ตอนนี้ — ว่างทั้งหมดหักที่ที่ถูกกันไว้ให้คิวรอ
     * ต้องเรียกผ่าน scopeWithHeldSeats ก่อน ไม่งั้นจะถือว่าไม่มีที่นั่งถูกกันไว้
     */
    public function getBookableSeatsAttribute(): int
    {
        return max(0, $this->available_seats - (int) ($this->held_seats ?? 0));
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

    /**
     * ที่จอยทริปที่ยังว่าง — คนจอยไม่กินที่นั่งรถ (booked_seats ไม่นับให้)
     * จึงมีโควตาแยกของตัวเอง คืน null เมื่อแอดมินไม่ได้กำหนดเพดานไว้
     * ซึ่งแปลว่า "ไม่จำกัด" (พฤติกรรมเดิมก่อนมีฟีเจอร์นี้)
     */
    public function getJoinTripAvailableSeatsAttribute(): ?int
    {
        if ($this->join_trip_seats === null) {
            return null;
        }

        return max(0, (int) $this->join_trip_seats - (int) $this->join_trip_booked_seats);
    }

    /**
     * จอยทริปเต็มหรือยัง — รอบที่ไม่ได้กำหนดเพดานไว้ไม่มีวันเต็ม
     */
    public function joinTripIsFull(): bool
    {
        return $this->join_trip_available_seats !== null
            && $this->join_trip_available_seats <= 0;
    }

    /**
     * รับคนจอยเพิ่มอีก $count คนได้ไหม — ใช้ก่อนสร้าง/ย้ายการจองแบบจอยทริป
     */
    public function canFitJoinTrip(int $count): bool
    {
        $available = $this->join_trip_available_seats;

        return $available === null || $available >= $count;
    }

    public function getEffectivePriceAttribute(): float
    {
        if ($this->flashSaleActive()) {
            return (float) $this->flash_sale_price;
        }

        return $this->price_override ?? $this->trip->price_per_person;
    }

    /**
     * The pre-discount selling price for this round (round price override, else
     * the trip's base price). Shown struck-through beside the flash-sale price.
     */
    public function getOriginalPriceAttribute(): float
    {
        return $this->price_override ?? $this->trip->price_per_person;
    }

    /**
     * Whether a flash sale is currently live for this round: admin-enabled with
     * a price, past its (optional) scheduled start, still within the (optional)
     * end time, and the round is actually sellable — open, upcoming, with seats
     * left. Once any of those lapse the effective price falls back to the normal
     * price automatically.
     */
    public function flashSaleActive(): bool
    {
        if (! $this->flash_sale_enabled || $this->flash_sale_price === null) {
            return false;
        }

        // Scheduled to start later — configured but dormant until then.
        if ($this->flash_sale_starts_at !== null && $this->flash_sale_starts_at->isFuture()) {
            return false;
        }

        if ($this->flash_sale_ends_at !== null && $this->flash_sale_ends_at->isPast()) {
            return false;
        }

        if ($this->status !== 'open' || $this->available_seats <= 0) {
            return false;
        }

        $departsAt = $this->effectiveDepartsAt();

        return $departsAt === null || $departsAt->isFuture();
    }

    /**
     * A flash sale that is enabled and priced but hasn't reached its scheduled
     * start yet — configured, waiting to go live. The customer UI keys off
     * flashSaleActive(), so these stay invisible until they start.
     */
    public function flashSaleUpcoming(): bool
    {
        return $this->flash_sale_enabled
            && $this->flash_sale_price !== null
            && $this->flash_sale_starts_at !== null
            && $this->flash_sale_starts_at->isFuture();
    }

    /**
     * Scope: rounds whose scheduled flash sale has just gone live (start passed,
     * still enabled). Used by StartScheduledFlashSalesJob to fire the launch push
     * the moment the sale begins; flashSaleActive() + the broadcast dedupe ledger
     * keep it to a single announcement.
     */
    public function scopeFlashSaleJustStarted($query)
    {
        return $query->where('flash_sale_enabled', true)
            ->whereNotNull('flash_sale_price')
            ->whereNotNull('flash_sale_starts_at')
            ->where('flash_sale_starts_at', '<=', now())
            ->where(function ($q) {
                $q->whereNull('flash_sale_ends_at')->orWhere('flash_sale_ends_at', '>', now());
            });
    }

    /** รอบนี้บินไปไหม. */
    public function isFlight(): bool
    {
        return $this->transport_type === self::TRANSPORT_FLIGHT;
    }

    /**
     * เวลานัดพบที่สนามบิน เป็นเวลาไทย (wall-clock เหมือน departs_at)
     *
     * ผูกกับ "วันของเวลาออกเดินทางจริง" ไม่ใช่ departure_date เพราะไฟลต์ดึก
     * (ออก 00:30 ของวันที่ 5) นัดเจอกันตั้งแต่ 21:30 ของวันที่ 4 — ถ้าเอา
     * meeting_time ไปต่อกับ departure_date ตรง ๆ จะได้เวลานัดพบ *หลัง* เครื่องออก
     * กฎที่ใช้: ต่อกับวันของ departs_at ก่อน แล้วถ้าออกมาเลยเวลาเครื่องออกไปแล้ว
     * ให้ถอยหนึ่งวัน
     *
     * ดู [reference-departs-at-timezone] — คอลัมน์เก็บเวลาไทยในชนิด UTC
     */
    public function meetingAt(): ?Carbon
    {
        if (blank($this->meeting_time)) {
            return null;
        }

        [$hour, $minute] = array_pad(explode(':', (string) $this->meeting_time), 2, '0');

        $anchor = $this->departs_at ?? $this->departure_date;
        if (! $anchor) {
            return null;
        }

        $meeting = $anchor->copy()->setTime((int) $hour, (int) $minute);

        if ($this->departs_at && $meeting->gt($this->departs_at)) {
            $meeting->subDay();
        }

        return $meeting;
    }

    /**
     * ขาบินของรอบนี้ แยกเป็นขาไป/ขากลับ ในรูปที่ client เอาไปวาดได้เลย
     *
     * @return array{outbound: array<int, array<string, mixed>>, return: array<int, array<string, mixed>>}
     */
    public function flightLegs(): array
    {
        $legs = collect($this->flights ?? [])
            ->filter(fn ($leg) => is_array($leg))
            ->values();

        return [
            'outbound' => $legs->where('direction', '!=', 'return')->values()->all(),
            'return' => $legs->where('direction', 'return')->values()->all(),
        ];
    }

    /**
     * ลูกค้าเลือกที่นั่งเองได้ไหม
     *
     * ที่นั่งบนเครื่องบินสายการบินเป็นคนจัดตอนออกตั๋ว ผังของเราจึงไม่มีความหมาย
     * และการปล่อยให้ลูกค้าเลือกจะกลายเป็นคำสัญญาที่เราทำตามไม่ได้ — รอบแบบนี้
     * ทีมงานเป็นคนกรอกเลขที่นั่งจริงกลับเข้าไปในการจองทีหลัง (หน้าแก้ไขการจอง)
     */
    public function allowsSeatSelection(): bool
    {
        return ! $this->isFlight();
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
     * สถานะการันตีออกเดินทางของรอบนี้ ตามจำนวนที่นั่งที่จองแล้ว
     * คืน null สำหรับทริปเหมาคัน (is_charter) เพราะออกเดินทางแน่นอนอยู่แล้ว
     * ไม่ต้องพึ่งจำนวนผู้ร่วมทาง
     */
    /**
     * ที่นั่งขั้นต่ำที่การันตีออกเดินทาง — แอดมินปรับได้ที่หน้าตั้งค่าระบบ
     */
    public static function guaranteeMinSeats(): int
    {
        return max(1, SiteSettings::int('guarantee_min_seats'));
    }

    public function departureStatus(): ?string
    {
        if ($this->is_charter) {
            return null;
        }

        $booked = (int) $this->booked_seats;

        if ($booked >= self::guaranteeMinSeats()) {
            return self::STATUS_GUARANTEED;
        }

        if ($booked >= self::ALMOST_READY_MIN_SEATS) {
            return self::STATUS_ALMOST_READY;
        }

        return self::STATUS_WAITING;
    }

    /**
     * จำนวนที่นั่งที่ยังขาดเพื่อให้รอบนี้การันตีออกเดินทาง (0 = ครบแล้ว)
     */
    public function seatsToGuarantee(): int
    {
        return max(0, self::guaranteeMinSeats() - (int) $this->booked_seats);
    }

    /**
     * Recalculate and sync the booked_seats counter from actual bookings.
     *
     * นับสองตัวในคิวรีเดียว: ที่นั่งบนรถ (จองปกติ) กับที่จอยทริป — สองอย่างนี้
     * แยกโควตากันคนละกอง แต่เปลี่ยนพร้อมกันเสมอ ทุกจุดที่เรียกเมธอดนี้อยู่แล้ว
     * จึงได้ตัวนับจอยทริปที่ตรงไปด้วยโดยไม่ต้องแก้อะไร
     *
     * คืนค่าจำนวนที่นั่งบนรถเหมือนเดิม (ผู้เรียกเดิมยังใช้ค่านี้อยู่)
     */
    public function syncBookedSeats(): int
    {
        $activePassengers = fn () => BookingPassenger::query()
            ->join('bookings', 'bookings.id', '=', 'booking_passengers.booking_id')
            ->where('bookings.schedule_id', $this->id)
            ->whereIn('bookings.status', self::ACTIVE_BOOKING_STATUSES);

        $total = $activePassengers()->count();
        $joinCount = $activePassengers()->where('bookings.is_join_trip', true)->count();
        $count = $total - $joinCount;

        $this->update([
            'booked_seats' => $count,
            'join_trip_booked_seats' => $joinCount,
        ]);
        $this->booked_seats = $count;
        $this->join_trip_booked_seats = $joinCount;

        return $count;
    }
}
