<?php

namespace App\Http\Resources;

use App\Support\MediaDisk;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'photo' => $this->photo,
            'line_id' => $this->line_id,
            'notes' => $this->notes,
            'is_active' => (bool) $this->is_active,

            // ใบขับขี่ — license_days_left/status คำนวณให้ฝั่งหน้าเว็บไม่ต้องนับวันเอง
            'license_number' => $this->license_number,
            'license_type' => $this->license_type,
            'license_expires_at' => $this->license_expires_at?->format('Y-m-d'),
            'license_days_left' => $this->licenseDaysLeft(),
            'license_status' => $this->licenseStatus(),
            // รูปใบขับขี่อยู่บนดิสก์ส่วนตัว ส่งออกเป็นลิงก์เซ็นชื่ออายุสั้นเท่านั้น
            'has_license_photo' => filled($this->license_photo),
            'license_photo_url' => MediaDisk::privateUrl($this->license_photo),

            // ตัวตนและผู้ติดต่อฉุกเฉิน
            'id_card' => $this->id_card,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'address' => $this->address,
            'emergency_contact' => $this->emergency_contact,
            'emergency_phone' => $this->emergency_phone,

            // รหัสส่ง GPS ผูกกับคนขับ ตั้งครั้งเดียวใช้ได้ทุกคันที่เขาขับ
            'has_pin' => $this->hasPin(),

            'vehicles_count' => $this->whenCounted('vehicles'),
            'vehicles' => $this->whenLoaded('vehicles', fn () => $this->vehicles->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'type' => $v->type,
                'capacity' => $v->capacity,
                'license_plate' => $v->license_plate,
                'color' => $v->color,
                'has_driver_pin' => $v->hasDriverPin(),
            ])),
            'last_trip_date' => $this->whenNotNull(
                $this->last_trip_date ? Carbon::parse($this->last_trip_date)->toDateString() : null,
            ),
            'upcoming_trips_count' => $this->when(
                isset($this->upcoming_trips_count),
                fn () => (int) $this->upcoming_trips_count,
            ),
        ];
    }
}
