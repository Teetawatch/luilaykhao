<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleInspection extends Model
{
    /**
     * Canonical pre-trip checklist. Ordered as shown to the driver. `critical`
     * items are safety-blocking — failing one flags the departure as risky.
     */
    public const ITEMS = [
        ['key' => 'tires', 'label' => 'ยางและแรงดันลมยาง', 'critical' => true],
        ['key' => 'lights', 'label' => 'ไฟหน้า–ท้าย–เลี้ยว', 'critical' => true],
        ['key' => 'brakes', 'label' => 'ระบบเบรก', 'critical' => true],
        ['key' => 'fuel', 'label' => 'ระดับน้ำมันเชื้อเพลิง', 'critical' => true],
        ['key' => 'fluids', 'label' => 'น้ำมันเครื่อง / น้ำหล่อเย็น', 'critical' => false],
        ['key' => 'seatbelts', 'label' => 'เข็มขัดนิรภัยครบทุกที่นั่ง', 'critical' => true],
        ['key' => 'first_aid', 'label' => 'ชุดปฐมพยาบาล', 'critical' => true],
        ['key' => 'fire_extinguisher', 'label' => 'ถังดับเพลิง', 'critical' => true],
        ['key' => 'documents', 'label' => 'เอกสารรถ / ประกัน', 'critical' => false],
        ['key' => 'cleanliness', 'label' => 'ความสะอาดภายในรถ', 'critical' => false],
    ];

    protected $fillable = [
        'schedule_id',
        'vehicle_id',
        'inspected_by',
        'items',
        'passed',
        'critical_failed',
        'note',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'passed' => 'boolean',
            'critical_failed' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /** key => [label, critical] for validating/merging submitted items. */
    public static function itemsByKey(): array
    {
        return collect(self::ITEMS)->keyBy('key')->all();
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}
