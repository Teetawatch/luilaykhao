<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * ประเภทรถรับ-ส่งจากจุดรับต่างภูมิภาค (ดูหมายเหตุใน migration)
 *
 * เป็นไกด์ให้ลูกค้าเห็นว่าค่าจุดรับที่จ่ายเพิ่มได้รถแบบไหน ไม่ใช่การผูกรถ
 * คันจริง — รถคันจริงมาจากตาราง vehicles และตัดสินตอนจัดรอบ
 */
class PickupVehicleClass extends Model
{
    use HasFactory;

    protected $fillable = [
        'label', 'min_pax', 'max_pax', 'image_url', 'note', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'min_pax' => 'integer',
            'max_pax' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('min_pax')->orderBy('id');
    }

    /** ผู้โดยสาร $pax คน เข้าเกณฑ์รถแบบนี้ไหม (max_pax = null คือ "ขึ้นไป") */
    public function covers(int $pax): bool
    {
        return $pax >= $this->min_pax && ($this->max_pax === null || $pax <= $this->max_pax);
    }

    /**
     * ช่วงจำนวนคนแบบอ่านออก — "1-2 ท่าน", "5 ท่าน", "6 ท่านขึ้นไป"
     */
    public function paxLabel(): string
    {
        if ($this->max_pax === null) {
            return "{$this->min_pax} ท่านขึ้นไป";
        }

        if ($this->max_pax === $this->min_pax) {
            return "{$this->min_pax} ท่าน";
        }

        return "{$this->min_pax}-{$this->max_pax} ท่าน";
    }

    /**
     * รายการที่เปิดใช้งาน เรียงตามลำดับที่แอดมินจัดไว้
     *
     * @return Collection<int, self>
     */
    public static function published(): Collection
    {
        return static::query()->active()->ordered()->get();
    }
}
