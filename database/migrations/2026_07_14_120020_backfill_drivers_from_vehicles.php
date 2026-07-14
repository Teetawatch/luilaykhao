<?php

use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * สร้าง record ในทะเบียนคนขับจากข้อมูลคนขับที่ฝังอยู่ในรถเดิม
     * แล้วผูก vehicles.driver_id — คนขับชื่อ+เบอร์เดียวกันจะรวมเป็น record เดียว
     */
    public function up(): void
    {
        Vehicle::query()
            ->whereNotNull('driver_name')
            ->where('driver_name', '!=', '')
            ->whereNull('driver_id')
            ->get()
            ->each(function (Vehicle $vehicle) {
                $driver = Driver::firstOrCreate(
                    [
                        'name' => $vehicle->driver_name,
                        'phone' => $vehicle->driver_phone,
                    ],
                    [
                        'photo' => $vehicle->driver_photo,
                    ],
                );

                // เติมรูปให้ record เดิมถ้ายังไม่มี
                if (empty($driver->photo) && ! empty($vehicle->driver_photo)) {
                    $driver->forceFill(['photo' => $vehicle->driver_photo])->save();
                }

                $vehicle->forceFill(['driver_id' => $driver->id])->save();
            });
    }

    public function down(): void
    {
        // เก็บทะเบียนคนขับที่ backfill ไว้ (ข้อมูลเดิมบนรถยังอยู่ครบ)
    }
};
