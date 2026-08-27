<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'capacity' => $this->capacity,
            'seat_layout' => $this->seat_layout,
            'license_plate' => $this->license_plate,
            'color' => $this->color,
            'driver_id' => $this->driver_id,
            // ข้อมูลคนขับทั้งใบจากทะเบียน — หน้ายานพาหนะจะได้แสดงครบโดยไม่ต้องยิงซ้ำ
            // และไม่ต้องให้แอดมินกรอกอะไรของ "คน" ซ้ำอีกเลย
            'driver' => $this->whenLoaded('driver', fn () => $this->driver
                ? new DriverResource($this->driver)
                : null),
            'driver_name' => $this->driver_name,
            'driver_phone' => $this->driver_phone,
            'driver_user_id' => $this->driver_user_id,
            // whenLoaded() คืน null ทันทีที่ relation ถูกโหลดแล้วได้ค่าว่าง ทำให้ค่านี้เคยเป็น
            // null แทน false ในรถที่ยังไม่มีบัญชีคนขับ — เช็คเองเพื่อให้เป็น boolean เสมอ
            'has_driver_pin' => $this->relationLoaded('driverUser') && $this->hasDriverPin(),
            'driver_photo' => $this->driver_photo,
            'interior_video' => $this->interior_video,
            'images' => $this->images ?? [],
            'pickup_points' => $this->whenLoaded('pickupPoints', fn () => $this->pickupPoints->map(fn ($p) => [
                'id' => $p->id,
                'region' => $p->region,
                'region_label' => $p->region_label,
                'pickup_location' => $p->pickup_location,
                'map_url' => $p->map_url,
                'image_url' => $p->image_url,
                'latitude' => $p->latitude,
                'longitude' => $p->longitude,
                'notes' => $p->notes,
                'sort_order' => $p->sort_order,
            ])
            ),
            'schedules_count' => $this->whenCounted('schedules'),
            'upcoming_schedules_count' => $this->whenCounted('upcomingSchedules'),
            'last_departure_date' => $this->schedules_max_departure_date ?? null,
            // เคยมีรอบ แต่ไม่เหลือรอบข้างหน้าแล้ว → หน้าจัดการยานพาหนะซ่อนไว้ในแท็บ "เลิกใช้แล้ว"
            'is_retired' => $this->when(
                isset($this->schedules_count, $this->upcoming_schedules_count),
                fn () => $this->schedules_count > 0 && $this->upcoming_schedules_count === 0,
            ),
        ];
    }
}
