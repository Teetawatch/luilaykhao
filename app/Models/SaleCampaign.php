<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * แคมเปญวันพิเศษระดับเว็บ — 9.9, 10.10, 12.12 แบบที่ร้านค้าออนไลน์ทำกัน
 * ตั้งครั้งเดียวแล้วราคาทริป "ทุกรอบที่ยังเปิดขาย" ลดพร้อมกัน พอหมดเวลา
 * ราคาเด้งกลับเองโดยไม่ต้องมีใครไปไล่ปิด
 *
 * ต่างจากของเดิมสองอย่างที่ชื่อคล้ายกัน:
 * - flash sale บน TripSchedule = ราคาพิเศษ "รายรอบ" ที่แอดมินพิมพ์เอง
 * - flash sale บน Promotion    = โค้ดส่วนลดที่ลูกค้าต้องกรอก
 * แคมเปญนี้ไม่ต้องกรอกอะไร และไม่ผูกกับรอบใดรอบหนึ่ง
 */
class SaleCampaign extends Model
{
    protected $fillable = [
        'name',
        'badge_label',
        'tagline',
        'discount_type',
        'discount_value',
        'max_discount',
        'starts_at',
        'ends_at',
        'is_active',
        'excluded_trip_ids',
        'theme_color',
        'announced_at',
        'announce_on_start',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'excluded_trip_ids' => 'array',
            'announced_at' => 'datetime',
            'announce_on_start' => 'boolean',
        ];
    }

    public const TYPE_PERCENT = 'percent';

    public const TYPE_AMOUNT = 'amount';

    /** กำลังลดราคาอยู่จริง ณ ตอนนี้ */
    public function isLive(): bool
    {
        return $this->is_active
            && $this->starts_at !== null
            && $this->ends_at !== null
            && ! $this->starts_at->isFuture()
            && $this->ends_at->isFuture();
    }

    /** ตั้งไว้แล้วรอถึงเวลา — ยังไม่มีผลกับราคาใด ๆ */
    public function isUpcoming(): bool
    {
        return $this->is_active
            && $this->starts_at !== null
            && $this->starts_at->isFuture();
    }

    /** Scope: แคมเปญที่มีผลอยู่ตอนนี้ */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());
    }

    /** ทริปนี้ร่วมแคมเปญไหม — ยกเว้นได้เป็นรายทริป */
    public function appliesToTrip(?int $tripId): bool
    {
        if ($tripId === null) {
            return false;
        }

        return ! in_array($tripId, array_map('intval', $this->excluded_trip_ids ?? []), true);
    }

    /**
     * ส่วนลดต่อคนที่แคมเปญนี้ให้กับราคา $price บาท
     *
     * เพดาน max_discount ใช้กับทั้งสองแบบ และส่วนลดไม่มีทางเกินราคาเต็ม
     * (ทริป ฿1,000 กับแคมเปญ "ลด ฿1,500" ต้องได้ ฿0 ไม่ใช่ติดลบ)
     */
    public function discountOn(float $price): float
    {
        if ($price <= 0) {
            return 0.0;
        }

        $value = (float) $this->discount_value;

        $discount = $this->discount_type === self::TYPE_PERCENT
            ? $price * ($value / 100)
            : $value;

        if ($this->max_discount !== null) {
            $discount = min($discount, (float) $this->max_discount);
        }

        return round(min(max($discount, 0.0), $price), 2);
    }

    /** ราคาหลังหักส่วนลดแคมเปญ */
    public function priceFor(float $price): float
    {
        return round($price - $this->discountOn($price), 2);
    }

    /** ป้ายสั้น ๆ ที่ใช้โชว์ เช่น "ลด 15%" หรือ "ลด ฿500" */
    public function discountLabel(): string
    {
        if ($this->discount_type === self::TYPE_PERCENT) {
            return 'ลด '.rtrim(rtrim(number_format((float) $this->discount_value, 2), '0'), '.').'%';
        }

        return 'ลด ฿'.number_format((float) $this->discount_value);
    }
}
