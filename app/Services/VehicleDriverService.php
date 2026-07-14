<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * จัดการ "รหัสส่ง GPS (PIN)" ของคนข้ับประจำรถ
 *
 * แต่ละรถผูกกับบัญชีคนขับ (role: driver) ผ่าน vehicles.driver_user_id
 * บัญชีนี้ใช้แค่ PIN ล็อกอินที่ /driver/track เพื่อส่ง GPS เท่านั้น
 * จึงถูกซ่อนจากเมนู "ผู้ใช้งานระบบ" และจัดการได้จากหน้ายานพาหนะโดยตรง
 */
class VehicleDriverService
{
    public const DRIVER_ROLE = 'driver';

    /**
     * ตั้ง/เปลี่ยนรหัสส่ง GPS ของรถ — สร้างบัญชีคนขับให้อัตโนมัติถ้ายังไม่มี
     *
     * @throws \RuntimeException เมื่อ PIN ซ้ำกับคนขับคนอื่น
     */
    public function setPin(Vehicle $vehicle, string $pin): User
    {
        $this->assertPinAvailable($pin, $vehicle->driver_user_id);

        $driver = $vehicle->driverUser;

        if (! $driver) {
            $driver = User::create([
                'name' => $vehicle->driver_name ?: ('คนขับ '.$vehicle->name),
                'email' => 'driver-'.Str::lower(Str::random(12)).'@gps.local',
                'phone' => $vehicle->driver_phone,
                'password' => Hash::make(Str::random(32)),
                'driver_pin_hash' => Hash::make($pin),
            ]);

            $vehicle->forceFill(['driver_user_id' => $driver->id])->save();
        } else {
            $driver->forceFill([
                'name' => $vehicle->driver_name ?: $driver->name,
                'phone' => $vehicle->driver_phone ?: $driver->phone,
                'driver_pin_hash' => Hash::make($pin),
            ])->save();
        }

        $driver->assignRole(Role::firstOrCreate([
            'name' => self::DRIVER_ROLE,
            'guard_name' => 'web',
        ]));

        return $driver;
    }

    /**
     * ลบรหัสส่ง GPS ของรถ (ปิดการส่ง GPS ของคนขับคนนี้)
     */
    public function clearPin(Vehicle $vehicle): void
    {
        $driver = $vehicle->driverUser;
        if ($driver) {
            $driver->forceFill(['driver_pin_hash' => null])->save();
        }
    }

    /**
     * คัดลอกข้อมูลคนขับจากทะเบียนคนขับ (drivers) ลงเป็น snapshot บนตัวรถ
     * เพื่อให้โค้ดเดิมที่อ่าน driver_name/driver_phone/driver_photo จากรถทำงานได้เหมือนเดิม
     *
     * เรียกก่อนบันทึกรถ หลัง fill ค่า driver_id แล้ว
     */
    public function applyDriverSnapshot(Vehicle $vehicle): void
    {
        if (! $vehicle->driver_id) {
            return;
        }

        $driver = $vehicle->driver()->first();
        if (! $driver) {
            return;
        }

        $vehicle->forceFill([
            'driver_name' => $driver->name,
            'driver_phone' => $driver->phone,
            'driver_photo' => $driver->photo,
        ]);
    }

    /**
     * ซิงก์ชื่อ/เบอร์ของบัญชีคนขับให้ตรงกับข้อมูลรถ (เรียกหลังแก้ไขข้อมูลรถ)
     */
    public function syncDriverProfile(Vehicle $vehicle): void
    {
        $driver = $vehicle->driverUser;
        if (! $driver) {
            return;
        }

        $driver->forceFill([
            'name' => $vehicle->driver_name ?: $driver->name,
            'phone' => $vehicle->driver_phone ?: $driver->phone,
        ])->save();
    }

    /**
     * PIN ต้องไม่ซ้ำกับคนขับคนอื่น (pinLogin เทียบทีละคน คนแรกที่ตรงชนะ)
     *
     * @throws \RuntimeException
     */
    private function assertPinAvailable(string $pin, ?int $ignoreUserId): void
    {
        $clash = User::whereNotNull('driver_pin_hash')
            ->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))
            ->get()
            ->contains(fn (User $candidate) => Hash::check($pin, $candidate->driver_pin_hash));

        if ($clash) {
            throw new \RuntimeException('รหัส PIN นี้ถูกใช้กับคนขับคนอื่นแล้ว กรุณาเลือกรหัสอื่น');
        }
    }
}
