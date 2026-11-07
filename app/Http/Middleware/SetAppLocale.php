<?php

namespace App\Http\Middleware;

use App\Support\AppLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ตั้งภาษาของ request จาก header `X-App-Language` (ดู AppLocale) แล้วจำไว้ที่
 * users.locale เมื่อผู้ใช้ล็อกอินอยู่ — push ที่ส่งตอนแอปไม่ได้เปิดจะได้ตรงภาษา
 *
 * request ที่ไม่ส่ง header มา (เว็บ, LIFF, แอปรุ่นเก่า) ใช้ภาษาไทย และไม่แตะ
 * users.locale เลย เปิดแอปภาษาอังกฤษแล้วสั่งจองผ่านเว็บต่อ push ก็ยังเป็นอังกฤษ
 */
class SetAppLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = AppLocale::fromRequest($request);
        app()->setLocale($locale ?? AppLocale::THAI);

        $response = $next($request);

        // อ่านหลัง $next: auth:sanctum เป็น route middleware จึงรู้ตัวผู้ใช้ทีหลัง
        // middleware กลุ่ม api — ไม่ต้องยิงหา token เองซ้ำ
        $user = $locale ? $request->user() : null;
        if ($user && $user->locale !== $locale) {
            $user->forceFill(['locale' => $locale])->saveQuietly();
        }

        return $response;
    }
}
