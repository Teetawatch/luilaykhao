<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * สตาฟกดปิดแชร์ตำแหน่งรถเอง — ดู [\App\Services\VehicleLocationService::recordStop]
 */
class VehicleShareStop extends Model
{
    /** เหตุผลที่สตาฟเลือกได้ — คีย์ถาวร (เก็บลงฐานข้อมูล) => คำที่แสดง */
    public const REASONS = [
        'arrived' => 'ถึงที่หมายแล้ว',
        'all_aboard' => 'รับครบทุกคนแล้ว',
        'battery' => 'แบตใกล้หมด',
        'other' => 'เหตุผลอื่น',
    ];

    protected $fillable = [
        'schedule_id',
        'vehicle_id',
        'user_id',
        'reason',
        'last_location_id',
        'stopped_at',
    ];

    protected function casts(): array
    {
        return [
            'stopped_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? self::REASONS['other'];
    }
}
