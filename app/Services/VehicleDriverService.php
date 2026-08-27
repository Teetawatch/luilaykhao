<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * จัดการ "รหัสส่ง GPS (PIN)" ของคนขับประจำรถ
 *
 * แต่ละรถผูกกับบัญชีคนขับ (role: driver) ผ่าน vehicles.driver_user_id
 * บัญชีนี้ใช้แค่ PIN ล็อกอินที่ /driver/track เพื่อส่ง GPS เท่านั้น
 * จึงถูกซ่อนจากเมนู "ผู้ใช้งานระบบ" และจัดการได้จากหน้ายานพาหนะโดยตรง
 *
 * ถ้ารถผูกกับ "ทะเบียนคนขับ" (vehicles.driver_id) บัญชีนั้นเป็นของ *คน* ไม่ใช่ของ *รถ*:
 * เก็บไว้ที่ drivers.pin_user_id แล้วแจกให้รถทุกคันที่คนขับคนนี้ผูกอยู่ ตั้ง PIN ครั้งเดียว
 * ใช้ได้ทุกคัน — เดิมสร้างบัญชีใหม่ทุกคันและ PIN ห้ามซ้ำ คนที่ขับสามคันจึงต้องจำสามรหัส
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
        $registryDriver = $vehicle->driver_id ? $vehicle->driver()->first() : null;

        // บัญชีที่ต้องใช้: ของคนขับในทะเบียนถ้ามี ไม่งั้นก็ของรถคันนี้เอง
        $account = $registryDriver?->pinUser ?: $vehicle->driverUser;

        $this->assertPinAvailable($pin, $account?->id);

        $displayName = $registryDriver?->name ?: $vehicle->driver_name;
        $displayPhone = $registryDriver?->phone ?: $vehicle->driver_phone;

        if (! $account) {
            $account = User::create([
                'name' => $displayName ?: ('คนขับ '.$vehicle->name),
                'email' => 'driver-'.Str::lower(Str::random(12)).'@gps.local',
                'phone' => $displayPhone,
                'password' => Hash::make(Str::random(32)),
                'driver_pin_hash' => Hash::make($pin),
            ]);
        } else {
            $account->forceFill([
                'name' => $displayName ?: $account->name,
                'phone' => $displayPhone ?: $account->phone,
                'driver_pin_hash' => Hash::make($pin),
            ])->save();
        }

        $account->assignRole(Role::firstOrCreate([
            'name' => self::DRIVER_ROLE,
            'guard_name' => 'web',
        ]));

        if ($registryDriver) {
            $registryDriver->forceFill(['pin_user_id' => $account->id])->save();
            $this->spreadPinAccount($registryDriver, $account->id);
        } else {
            $vehicle->forceFill(['driver_user_id' => $account->id])->save();
        }

        return $account;
    }

    /**
     * ให้รถทุกคันของคนขับคนนี้ชี้ไปที่บัญชีเดียวกัน — แอปคนขับหาตารางงานจาก
     * vehicles.driver_user_id ถ้าไม่แจกให้ครบ รถคันอื่นจะไม่โผล่ในแอปหลังล็อกอิน
     */
    private function spreadPinAccount(Driver $driver, int $accountId): void
    {
        // บัญชีเดิมของรถแต่ละคันกำลังจะถูกแทนที่ — จำไว้ก่อนเพื่อคืนรหัสที่ไม่มีใครใช้แล้ว
        // ไม่งั้นเลข PIN เก่าจะยังล็อกอินได้ทั้งที่ไม่เหลือรถให้ส่ง GPS และยังจองเลขนั้นไว้
        $replaced = $driver->vehicles()
            ->whereNotNull('driver_user_id')
            ->where('driver_user_id', '!=', $accountId)
            ->pluck('driver_user_id')
            ->unique();

        $driver->vehicles()
            ->where(fn ($query) => $query->whereNull('driver_user_id')->orWhere('driver_user_id', '!=', $accountId))
            ->update(['driver_user_id' => $accountId]);

        $replaced->each(fn (int $oldAccountId) => $this->releaseOrphanedPin($oldAccountId));
    }

    /**
     * ลบรหัสส่ง GPS (ปิดการส่ง GPS ของคนขับคนนี้)
     *
     * รถที่ผูกทะเบียนคนขับใช้บัญชีร่วมกัน การลบจึงมีผลกับรถทุกคันที่เขาขับ —
     * ฝั่งหน้าเว็บบอกไว้ก่อนกดแล้ว
     */
    public function clearPin(Vehicle $vehicle): void
    {
        $registryDriver = $vehicle->driver_id ? $vehicle->driver()->first() : null;
        $account = $registryDriver?->pinUser ?: $vehicle->driverUser;

        if ($account) {
            $account->forceFill(['driver_pin_hash' => null])->save();
        }
    }

    /**
     * ปล่อยบัญชี PIN ที่ไม่มีใครใช้แล้ว — เรียกหลังลบรถ หรือหลังย้ายรถไปใช้บัญชีอื่น
     *
     * บัญชีที่ยังเป็นของคนขับในทะเบียนต้องไม่ถูกแตะ แม้รถคันสุดท้ายของเขาจะถูกลบไป:
     * รหัสเป็นของ *คน* ที่ยังอยู่ในทะเบียน พรุ่งนี้ผูกรถคันใหม่ก็ต้องใช้รหัสเดิมได้เลย
     * ส่วนบัญชีที่ถูกสร้างมาเพื่อรถคันเดียว (ไม่ได้ผูกทะเบียน) ต้องคืนรหัส ไม่งั้นเลข
     * PIN นั้นจะถูกจองไว้ตลอดกาลโดยบัญชีที่ไม่มีใครใช้
     */
    public function releaseOrphanedPin(?int $accountId): void
    {
        if (! $accountId) {
            return;
        }

        if (Vehicle::where('driver_user_id', $accountId)->exists()) {
            return;
        }

        if (Driver::where('pin_user_id', $accountId)->exists()) {
            return;
        }

        User::where('id', $accountId)->update(['driver_pin_hash' => null]);
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

        // คนขับตั้ง PIN ไว้แล้ว รถคันที่เพิ่งผูกก็ใช้รหัสเดิมได้ทันที ไม่ต้องตั้งใหม่
        if ($driver->pin_user_id) {
            $vehicle->forceFill(['driver_user_id' => $driver->pin_user_id]);
        }
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
    public function assertPinAvailable(string $pin, ?int $ignoreUserId): void
    {
        $clash = User::whereNotNull('driver_pin_hash')
            ->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))
            ->get()
            ->first(fn (User $candidate) => Hash::check($pin, $candidate->driver_pin_hash));

        if ($clash) {
            throw new \RuntimeException($this->clashMessage($clash));
        }
    }

    /**
     * บอกให้ชัดว่ารหัสไปค้างอยู่ที่ใคร — PIN มาจากได้สองที่ (หน้ายานพาหนะ กับ
     * เมนูผู้ใช้งานระบบ) แอดมินจึงมักหาไม่เจอว่า "คนขับคนอื่น" คือใคร
     */
    private function clashMessage(User $clash): string
    {
        $holder = Vehicle::where('driver_user_id', $clash->id)->first();

        if ($holder) {
            return sprintf(
                'รหัส PIN นี้ถูกใช้กับคนขับ "%s" ของรถ "%s" อยู่แล้ว — ลบรหัสของรถคันนั้นก่อน หรือเลือกรหัสอื่น',
                $clash->name,
                $holder->name,
            );
        }

        return sprintf(
            'รหัส PIN นี้ถูกใช้กับผู้ใช้ "%s" ในเมนูผู้ใช้งานระบบอยู่แล้ว — ลบรหัสของผู้ใช้คนนั้นก่อน หรือเลือกรหัสอื่น',
            $clash->name,
        );
    }
}
