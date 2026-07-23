<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\SosAlert;
use App\Models\VehicleLocation;
use App\Support\MediaDisk;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ศูนย์เฝ้าระวัง SOS สำหรับทีมงานออฟฟิศ
 *
 * เดิมสัญญาณ SOS ที่ลูกค้ากดมองเห็นได้เฉพาะคนที่อยู่ในทริปเดียวกันผ่านแอป
 * (ดู [SosController]) — หน้านี้เปิดให้แอดมิน/operator เห็นทุกเคสจากส่วนกลาง
 * พร้อมบริบทที่ต้องใช้ตัดสินใจทันที: ใครกด อยู่รอบไหน เบอร์ติดต่อ พิกัด และ
 * ตำแหน่งรถคันล่าสุดของรอบนั้น เพื่อประเมินว่าคนกดอยู่ห่างจากรถแค่ไหน
 */
class AdminSosController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $query = SosAlert::with(['user', 'schedule.trip', 'schedule.vehicle', 'resolver'])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at');

        if (in_array($status, ['active', 'resolved'], true)) {
            $query->where('status', $status);
        }

        $alerts = $query->limit(200)->get();

        return $this->success([
            'alerts' => $alerts->map(fn (SosAlert $a) => $this->present($a))->values(),
            'active_count' => SosAlert::where('status', 'active')->count(),
        ]);
    }

    /**
     * นับเฉพาะเคสที่ยังไม่ปิด — หน้าแอดมินเรียกถี่เพื่อเด้งเตือน จึงแยกออกมา
     * ให้เบาที่สุด (ไม่โหลด relation ใด ๆ)
     */
    public function activeCount(): JsonResponse
    {
        return $this->success([
            'count' => SosAlert::where('status', 'active')->count(),
            'latest_id' => SosAlert::where('status', 'active')->max('id'),
        ]);
    }

    /**
     * ปิดเคสจากฝั่งออฟฟิศ — ต่างจาก [SosController::resolve] ตรงที่แอดมินปิดได้
     * ทุกเคสโดยไม่ต้องอยู่ในทริปนั้น และบันทึกโน้ตว่าจัดการอย่างไรไว้ในข้อความ
     */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $alert = SosAlert::findOrFail($id);

        if ($alert->status !== 'active') {
            return $this->success($this->present($alert->fresh(['user', 'schedule.trip', 'resolver'])), 'เคสนี้ปิดไปแล้ว');
        }

        $note = trim($data['note'] ?? '');
        $alert->update([
            'status' => 'resolved',
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
            'message' => $note !== ''
                ? trim(($alert->message ?? '')."\n[ทีมงาน] ".$note)
                : $alert->message,
        ]);

        return $this->success(
            $this->present($alert->fresh(['user', 'schedule.trip', 'schedule.vehicle', 'resolver'])),
            'ปิดเคส SOS แล้ว'
        );
    }

    private function present(SosAlert $alert): array
    {
        $schedule = $alert->schedule;
        $vehicle = $schedule?->vehicle;

        // ตำแหน่งรถล่าสุดของรอบนี้ — ช่วยให้ทีมงานบอกคนขับได้ว่าต้องวิ่งไปทางไหน
        $vehicleLocation = $vehicle
            ? VehicleLocation::where('vehicle_id', $vehicle->id)
                ->latest('recorded_at')
                ->first(['latitude', 'longitude', 'recorded_at'])
            : null;

        return [
            'id' => $alert->id,
            'status' => $alert->status,
            'message' => $alert->message,
            'photo_url' => MediaDisk::url($alert->photo_path),
            'latitude' => $alert->latitude,
            'longitude' => $alert->longitude,
            'created_at' => $alert->created_at?->toISOString(),
            'resolved_at' => $alert->resolved_at?->toISOString(),
            'resolved_by_name' => $alert->resolver?->name,

            'user_name' => $alert->user?->name,
            'contact_phone' => $alert->contact_phone ?: $alert->user?->phone,
            // ข้อมูลสุขภาพที่ลูกค้ากรอกไว้ — สำคัญมากตอนประสานหน่วยกู้ภัย
            'allergies' => $alert->user?->allergies,
            'health_notes' => $alert->user?->health_notes,

            'schedule_id' => $alert->schedule_id,
            'trip_title' => $schedule?->trip?->title,
            'departure_date' => $schedule?->departure_date?->toDateString(),
            'booking_ref' => $alert->user_id && $alert->schedule_id
                ? Booking::where('user_id', $alert->user_id)
                    ->where('schedule_id', $alert->schedule_id)
                    ->value('booking_ref')
                : null,

            'vehicle_plate' => $vehicle?->license_plate,
            'driver_name' => $vehicle?->driver_name,
            'driver_phone' => $vehicle?->driver_phone,
            'vehicle_latitude' => $vehicleLocation?->latitude,
            'vehicle_longitude' => $vehicleLocation?->longitude,
            'vehicle_located_at' => $vehicleLocation?->recorded_at?->toISOString(),
        ];
    }
}
