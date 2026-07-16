<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * รหัสล็อกอินคนขับแบบใช้ครั้งเดียว (QR) — แอดมินสร้าง QR ให้ คนขับสแกนเพื่อเข้าระบบ
 * โดยไม่ต้องพิมพ์ PIN
 *
 * เก็บใน cache ไม่ใช่ DB: หมดอายุเองอัตโนมัติ และดึงออก (pull) = ใช้ซ้ำไม่ได้
 * ตัวโค้ดไม่มีข้อมูลคนขับอยู่ในนั้น
 *
 * อายุ 24 ชม. เพราะแอดมินมักส่ง QR ให้คนขับล่วงหน้า (เช่นทาง LINE) แล้วคนขับ
 * ค่อยสแกนทีหลัง — ตัวกันหลักคือ "ใช้ได้ครั้งเดียว" ไม่ใช่ความสั้นของอายุ
 */
class DriverLoginCodeService
{
    public const TTL_HOURS = 24;

    private const PREFIX = 'driver_login_code:';

    /**
     * @return array{code: string, expires_at: Carbon}
     */
    public function issue(User $driver): array
    {
        $code = Str::random(40);
        $expiresAt = Carbon::now()->addHours(self::TTL_HOURS);

        Cache::put(self::PREFIX.$code, $driver->id, $expiresAt);

        return ['code' => $code, 'expires_at' => $expiresAt];
    }

    /**
     * แลกโค้ดเป็นบัญชีคนขับ — ใช้ได้ครั้งเดียว (pull ลบทิ้งทันที)
     */
    public function redeem(string $code): ?User
    {
        $driverId = Cache::pull(self::PREFIX.$code);

        return $driverId ? User::find($driverId) : null;
    }
}
