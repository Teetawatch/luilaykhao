<?php

namespace App\Http\Resources;

use App\Models\TripSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleVehicleOptionResource extends JsonResource
{
    private ?TripSchedule $schedule = null;

    /**
     * ผูกรอบที่กำลังเรนเดอร์เข้ามา เพื่อบอก client ได้ว่าคันนี้เลือกที่นั่งเองได้ไหม
     * โดยไม่ต้องให้ client ไปเทียบ vehicle_id เอง (กติกาอยู่ที่เซิร์ฟเวอร์ที่เดียว)
     */
    public function forSchedule(TripSchedule $schedule): self
    {
        $this->schedule = $schedule;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'schedule_id' => $this->schedule_id,
            'label' => $this->label,
            'transport_type' => $this->transport_type,
            'vehicle_id' => $this->vehicle_id,
            // ส่วนต่างต่อคน บวกจากราคาที่ลูกค้าเห็น (ติดลบได้ = ถูกกว่าราคาปกติ)
            'price_adjustment' => (float) $this->price_adjustment,
            // null = ไม่ได้กำหนดโควตาย่อย ใช้ที่นั่งว่างรวมของรอบ — UI ต้องเช็ค
            // null ก่อนขึ้นข้อความ "เหลือ N ที่"
            'seats' => $this->seats,
            'booked_seats' => (int) $this->booked_seats,
            'available_seats' => $this->available_seats,
            'is_sold_out' => $this->isSoldOut(),
            'note' => $this->note,
            'image_url' => $this->image_url,
            // เลือกที่นั่งบนผังได้ไหม — ผังของรอบเป็นของรถหลักคันเดียว คันอื่น
            // ทีมงานจัดที่นั่งหน้างาน (ScheduleVehicleOption::usesScheduleSeatMap)
            'uses_seat_map' => $this->when(
                $this->schedule !== null,
                fn () => $this->schedule->allowsSeatSelection()
                    && $this->resource->usesScheduleSeatMap($this->schedule)
            ),
            'sort_order' => (int) $this->sort_order,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
