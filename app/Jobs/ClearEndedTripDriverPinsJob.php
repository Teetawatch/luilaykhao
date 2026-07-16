<?php

namespace App\Jobs;

use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleDriverService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ล้าง "รหัสส่ง GPS (PIN)" ของรถทุกคันที่รอบเดินทางจบไปแล้ว
 *
 * PIN ผูกกับบัญชีคนขับ *ต่อคันรถ* และต้องไม่ซ้ำกันทั้งระบบ (pinLogin ใช้ PIN
 * ตัวเดียวชี้ว่าเป็นคนขับคนไหน) พอ PIN เก่าค้างอยู่กับรถที่ไม่ได้วิ่งแล้ว
 * แอดมินจึงตั้งรหัสเดิมให้คนขับคนเดิมบนรถคันใหม่ไม่ได้ — job นี้เก็บกวาดให้
 * รหัสถูกปล่อยคืนหลังจบรอบ
 *
 * รอบที่ล้างแล้วถูกทำเครื่องหมายด้วย driver_pin_cleared_at เพื่อไม่ให้รอบเก่า
 * ย้อนกลับมาล้าง PIN ใหม่ที่แอดมินตั้งไว้สำหรับรอบถัดไปของรถคันเดียวกัน
 */
class ClearEndedTripDriverPinsJob implements ShouldQueue
{
    use Queueable;

    public const TIMEZONE = 'Asia/Bangkok';

    public int $tries = 1;

    public function handle(VehicleDriverService $vehicleDrivers): int
    {
        $cleared = $this->clearEndedRounds($vehicleDrivers) + $this->clearOrphanedDriverAccounts();

        if ($cleared > 0) {
            Log::info('ClearEndedTripDriverPinsJob completed', ['pins_cleared' => $cleared]);
        }

        return $cleared;
    }

    /**
     * คืนรหัสของรถที่รอบเดินทางจบไปแล้ว
     */
    private function clearEndedRounds(VehicleDriverService $vehicleDrivers): int
    {
        // เผื่อคนขับที่ยังขับรถกลับดึก ๆ อยู่ — ล้างเฉพาะรอบที่จบตั้งแต่เมื่อวานลงไป
        $cutoff = now(self::TIMEZONE)->subDay()->toDateString();

        $schedules = TripSchedule::query()
            ->whereNull('driver_pin_cleared_at')
            ->whereNotNull('vehicle_id')
            ->whereDate('return_date', '<=', $cutoff)
            ->get();

        if ($schedules->isEmpty()) {
            return 0;
        }

        $vehicles = Vehicle::with('driverUser')
            ->whereIn('id', $schedules->pluck('vehicle_id')->unique())
            ->get()
            ->keyBy('id');

        $cleared = 0;

        foreach ($schedules as $schedule) {
            $vehicle = $vehicles->get($schedule->vehicle_id);

            // รถคันเดียวอาจมีหลายรอบที่จบแล้ว — clearPin เขียนทับ relation ที่โหลดไว้
            // ทำให้รอบถัดมาของรถคันเดิมเห็นว่าไม่มี PIN แล้วและข้ามไป
            if ($vehicle && $vehicle->hasDriverPin()) {
                $vehicleDrivers->clearPin($vehicle);
                $cleared++;
            }

            $schedule->forceFill(['driver_pin_cleared_at' => now()])->save();
        }

        return $cleared;
    }

    /**
     * คืนรหัสของบัญชีคนขับที่รถถูกลบไปแล้ว — บัญชีพวกนี้ล็อกรหัสไว้เฉย ๆ
     * ทั้งที่ไม่มีรถให้ส่ง GPS อีกแล้ว และไม่มีรอบเดินทางให้ job ข้างบนตามไปเจอ
     *
     * ดูจากอีเมล @gps.local ที่ VehicleDriverService สร้างให้ เพื่อไม่ให้ไปแตะ
     * PIN ของสตาฟ/แอดมินที่ตั้งจากเมนูผู้ใช้งานระบบ
     */
    private function clearOrphanedDriverAccounts(): int
    {
        $orphans = User::query()
            ->whereNotNull('driver_pin_hash')
            ->where('email', 'like', '%@gps.local')
            ->whereNotIn('id', Vehicle::whereNotNull('driver_user_id')->pluck('driver_user_id'))
            ->get();

        foreach ($orphans as $orphan) {
            $orphan->forceFill(['driver_pin_hash' => null])->save();
        }

        return $orphans->count();
    }
}
