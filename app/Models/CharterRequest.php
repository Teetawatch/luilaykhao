<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * คำขอเหมาทริป / จัดทริปส่วนตัว — ดู App\Services\CharterRequestService
 *
 * new → quoted → accepted → booked
 *          ↘ declined (ลูกค้าไม่เอา — ทีมงานเสนอใหม่ได้)
 * new/quoted/declined → rejected (ทีมงานรับไม่ได้) · new/quoted → cancelled (ลูกค้ายกเลิก)
 */
class CharterRequest extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_QUOTED = 'quoted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_BOOKED = 'booked';

    public const GROUP_TYPES = ['friends', 'family', 'company', 'school', 'other'];

    public const GROUP_TYPE_LABELS = [
        'friends' => 'กลุ่มเพื่อน',
        'family' => 'ครอบครัว',
        'company' => 'บริษัท / องค์กร',
        'school' => 'โรงเรียน / มหาวิทยาลัย',
        'other' => 'อื่น ๆ',
    ];

    /** ขั้นต่ำของกลุ่มที่รับเหมา — ต่ำกว่านี้จองรอบปกติได้อยู่แล้ว */
    public const MIN_GROUP_SIZE = 4;

    public const MAX_GROUP_SIZE = 300;

    /** ใบเสนอราคามีอายุเท่านี้ถ้าทีมงานไม่ได้กำหนด */
    public const DEFAULT_QUOTE_VALID_DAYS = 7;

    protected $fillable = [
        'ref', 'user_id', 'trip_id', 'destination', 'preferred_date', 'alternate_date', 'flexible_dates',
        'duration_days', 'group_size', 'pickup_area', 'budget_per_person', 'group_type', 'needs_tax_invoice',
        'contact_name', 'contact_phone', 'contact_line', 'note', 'status',
        'quote_trip_id', 'quote_departure_date', 'quote_return_date', 'quote_group_size',
        'quote_price_per_person', 'quote_total', 'quote_includes', 'quote_note', 'quote_valid_until',
        'quoted_at', 'quoted_by_id', 'responded_at', 'decline_reason', 'reject_reason', 'admin_note',
        'schedule_id', 'booking_id', 'booked_at',
    ];

    protected function casts(): array
    {
        return [
            'preferred_date' => 'date',
            'alternate_date' => 'date',
            'flexible_dates' => 'boolean',
            'duration_days' => 'integer',
            'group_size' => 'integer',
            'budget_per_person' => 'decimal:2',
            'needs_tax_invoice' => 'boolean',
            'quote_departure_date' => 'date',
            'quote_return_date' => 'date',
            'quote_group_size' => 'integer',
            'quote_price_per_person' => 'decimal:2',
            'quote_total' => 'decimal:2',
            'quote_valid_until' => 'date',
            'quoted_at' => 'datetime',
            'responded_at' => 'datetime',
            'booked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function quoteTrip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'quote_trip_id');
    }

    public function quotedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quoted_by_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public static function generateRef(): string
    {
        do {
            $ref = 'CH-'.now('Asia/Bangkok')->format('ymd').'-'.strtoupper(Str::random(4));
        } while (self::where('ref', $ref)->exists());

        return $ref;
    }

    /** ใบเสนอราคาเลยกำหนดตอบรับแล้ว (นับถึงสิ้นวันตามเวลาไทย) */
    public function quoteExpired(): bool
    {
        return $this->quote_valid_until !== null
            && $this->quote_valid_until->toDateString() < now('Asia/Bangkok')->toDateString();
    }

    public function canBeAccepted(): bool
    {
        return $this->status === self::STATUS_QUOTED && ! $this->quoteExpired();
    }

    public function destinationLabel(): string
    {
        return $this->trip?->title ?? $this->destination ?? 'ทริปส่วนตัว';
    }
}
