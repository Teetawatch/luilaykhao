<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** ข้อยกเว้นของห้องประเภทหนึ่งในคืนหนึ่ง — ปิดห้อง / ราคาพิเศษ */
class StayRoomNight extends Model
{
    protected $fillable = ['room_type_id', 'night', 'closed_units', 'price_override'];

    protected function casts(): array
    {
        return [
            'night' => 'date',
            'closed_units' => 'integer',
            'price_override' => 'decimal:2',
        ];
    }

    /** เก็บเป็น Y-m-d ล้วนทุกฐานข้อมูล — การเทียบช่วงคืนเป็นการเทียบสตริง */
    protected function night(): Attribute
    {
        return Attribute::make(set: fn ($value) => $value === null ? null : Carbon::parse($value)->toDateString());
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(StayRoomType::class, 'room_type_id');
    }
}
