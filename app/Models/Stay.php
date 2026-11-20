<?php

namespace App\Models;

use App\Models\Concerns\HasContentTranslations;
use App\Support\MediaDisk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * ที่พักชุมชนหนึ่งแห่ง — ดู App\Services\StayBookingService
 */
class Stay extends Model
{
    use HasContentTranslations;

    /** ช่องที่ระบบแปลให้ลูกค้าต่างชาติ (ดู ContentTranslationService) */
    protected array $translatableContent = ['name', 'community_name', 'province', 'district', 'summary', 'description', 'community_story', 'highlights', 'house_rules'];

    public const TYPES = [
        'homestay' => 'โฮมสเตย์',
        'resort' => 'รีสอร์ต',
        'guesthouse' => 'เกสต์เฮาส์',
        'camp' => 'ลานกางเต็นท์ / แคมป์',
        'other' => 'อื่น ๆ',
    ];

    public const LICENSE_TYPES = [
        'hotel' => 'ใบอนุญาตประกอบธุรกิจโรงแรม',
        'non_hotel' => 'แจ้งที่พักที่ไม่เป็นโรงแรม',
        'homestay_standard' => 'มาตรฐานโฮมสเตย์ไทย',
        'other' => 'อื่น ๆ',
    ];

    public const MODE_REQUEST = 'request';

    public const MODE_INSTANT = 'instant';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    protected $fillable = [
        'name', 'slug', 'type', 'community_name', 'province', 'district', 'address',
        'latitude', 'longitude', 'map_url', 'summary', 'description', 'community_story',
        'highlights', 'amenities', 'house_rules', 'check_in_time', 'check_out_time',
        'cover_image', 'gallery', 'booking_mode', 'cancellation_policy', 'commission_percent',
        'host_user_id', 'host_name', 'host_phone', 'host_line_id', 'host_token',
        'license_type', 'license_no', 'payout_account', 'status', 'sort_order',
        'rating_avg', 'rating_count', 'admin_note',
    ];

    protected $hidden = ['host_token', 'payout_account', 'admin_note'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'highlights' => 'array',
            'amenities' => 'array',
            'gallery' => 'array',
            'commission_percent' => 'decimal:2',
            'payout_account' => 'encrypted:array',
            'sort_order' => 'integer',
            'rating_avg' => 'decimal:2',
            'rating_count' => 'integer',
            'views_count' => 'integer',
        ];
    }

    public function trips(): BelongsToMany
    {
        return $this->belongsToMany(Trip::class, 'stay_trip')->withPivot('distance_note')->withTimestamps();
    }

    public function roomTypes(): HasMany
    {
        return $this->hasMany(StayRoomType::class)->orderBy('sort_order')->orderBy('id');
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(StayExperience::class)->orderBy('sort_order')->orderBy('id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(StayBooking::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(StayReview::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(StayPayout::class);
    }

    public function hostUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isBookable(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** ส่วนที่ถึงมือเจ้าของ/ชุมชน (%) — ตัวเลขที่โชว์ให้ลูกค้าเห็น */
    public function communitySharePercent(): float
    {
        return round(100 - (float) $this->commission_percent, 2);
    }

    public function policyLabel(): string
    {
        return (string) config('stays.policies.'.$this->cancellation_policy.'.label', $this->cancellation_policy);
    }

    public function coverUrl(): ?string
    {
        return MediaDisk::url($this->cover_image);
    }

    /** @return array<int, string> */
    public function galleryUrls(): array
    {
        return collect($this->gallery ?? [])
            ->map(fn ($path) => MediaDisk::url(is_array($path) ? ($path['url'] ?? null) : $path))
            ->filter()
            ->values()
            ->all();
    }

    public static function newHostToken(): string
    {
        do {
            $token = Str::random(40);
        } while (self::where('host_token', $token)->exists());

        return $token;
    }

    public function hostUrl(): ?string
    {
        return $this->host_token ? url('/host/'.$this->host_token) : null;
    }
}
