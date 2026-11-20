<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * ETA ร่วมของรถ (VehicleEtaService) — ยิงเมื่อคิดใหม่ทุก 3–5 นาทีหรือเมื่อหยุดคิด
 *
 * ช่องเดียวกับพิกัดสด (`vehicle-tracking.{vehicleId}`) แอปที่เปิดจอติดตามรถอยู่
 * ฟังช่องนี้อยู่แล้ว ไม่ต้องเชื่อมต่อเพิ่ม ข้อมูลเป็นของรอบ/จุดรับ ไม่มีข้อมูลลูกค้า
 * ไม่ส่งเส้นทาง (polyline) ไปด้วยเพื่อให้ข้อความเล็ก — แอปเห็น `route_id` ใหม่แล้ว
 * ค่อยดึงเส้นจาก GET tracking/schedules/{id}/eta (ไม่เสียค่า Google)
 */
class VehicleEtaUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array<string, mixed>  $payload */
    public function __construct(public int $vehicleId, public array $payload) {}

    public function broadcastOn(): array
    {
        return [new Channel("vehicle-tracking.{$this->vehicleId}")];
    }

    public function broadcastAs(): string
    {
        return 'eta.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
