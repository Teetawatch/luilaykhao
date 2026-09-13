<?php

namespace App\Support;

use App\Models\User;

/**
 * ลิงก์ร้านแอปและคำถามเดียวที่ทุกหน้าต้องถามก่อนชวนโหลด: "คนนี้มีแอปอยู่แล้วไหม"
 *
 * ลิงก์เดียวกันนี้ต้องไปโผล่ห้าที่ (หน้าเคลมบัญชี ใบเดินทาง อีเมลยืนยันการจอง
 * อีเมลใบเดินทาง และเว็บ) — ถ้าปล่อยให้แต่ละที่พิมพ์เอง วันที่ย้าย bundle id
 * หรือเปลี่ยนร้านจะมีที่ใดที่หนึ่งค้างอยู่เสมอ และไม่มีใครรู้จนลูกค้าทัก
 *
 * ค่าอ่านจาก config/app.php ชุดเดียวกับที่ version gate ของแอปใช้อยู่แล้ว
 */
class AppLinks
{
    /** ลิงก์ App Store (iOS) */
    public static function ios(): string
    {
        return (string) config('app.mobile_ios_store_url');
    }

    /** ลิงก์ Google Play (Android) */
    public static function android(): string
    {
        return (string) config('app.mobile_android_store_url');
    }

    /**
     * เลขแอปตัวเลขล้วนของ App Store — Safari บน iPhone ใช้ค่านี้ในแท็ก
     * <meta name="apple-itunes-app"> เพื่อขึ้นแถบแนะนำแอปของ Apple เอง
     *
     * แกะจาก URL ร้านเพื่อไม่ให้ต้องดูแลเลขเดียวกันสองที่ (`.../idXXXXXXXX?l=th`)
     * คืนค่าว่างเมื่อแกะไม่ได้ — ฝั่งที่เรียกจะไม่พิมพ์แท็กนั้นออกมาเลย ดีกว่า
     * พิมพ์แท็กที่ชี้ไปแอปผิดตัว
     */
    public static function appleAppStoreId(): string
    {
        if ($explicit = trim((string) config('app.apple_app_store_id'))) {
            return $explicit;
        }

        preg_match('/\/id(\d+)/', self::ios(), $matches);

        return $matches[1] ?? '';
    }

    /**
     * ลูกค้าคนนี้เปิดแอปมาแล้วหรือยัง
     *
     * ใช้ "เคยลงทะเบียนโทเคนแจ้งเตือน" เป็นตัวชี้วัด เพราะแอปลงทะเบียนตั้งแต่
     * เปิดครั้งแรกหลังล็อกอิน คนที่ผ่านจุดนั้นแล้วไม่ควรเห็นโฆษณาของที่ตัวเอง
     * ถืออยู่ในมือ — และการเห็นมันบ่อย ๆ ทำให้คำชวนครั้งที่สำคัญจริงหมดความหมาย
     *
     * ไม่นับโทเคนที่ถูกปิดไปแล้ว (ถอนแอป/ปฏิเสธการแจ้งเตือน) คนกลุ่มนั้นชวนใหม่ได้
     */
    public static function hasApp(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->fcmTokens()->where('is_active', true)->exists();
    }
}
