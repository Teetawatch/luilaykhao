<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ช่องทางที่ request มาจาก — เว็บ (Vue SPA), แอปลูกค้า, LIFF หรือแอปคนขับ
 *
 * ทุกช่องทางยิง /api/v1 ชุดเดียวกัน จึงต้องมีที่เดียวที่ตัดสินว่าใครเป็นใคร:
 *  1. ชื่อ token ของ Sanctum — ออกตามช่องทางที่ล็อกอิน (issueToken) เชื่อได้ที่สุด
 *     เพราะ client แต่งเองไม่ได้
 *  2. header `X-Client` (web|app|liff) ที่เว็บและแอปส่งมา หรือ `X-Platform`
 *     (ios|android) ของแอป — ใช้กับ token รุ่นเก่าชื่อ `auth-token` และตอนยังไม่ล็อกอิน
 *  3. ไม่มีสัญญาณอะไรเลย = null (ไม่รู้) ไม่เดา
 *
 * token เว็บมีวันหมดอายุแบบเลื่อน (sliding) — ใช้งานอยู่เรื่อย ๆ ไม่หลุด แต่ token
 * ที่ถูกขโมยไปจาก localStorage แล้วไม่มีใครใช้นาน ๆ จะตายเอง ส่วนแอป/LIFF เก็บ
 * token ในที่ปลอดภัยของเครื่อง/LINE จึงไม่หมดอายุเหมือนเดิม
 */
final class ClientChannel
{
    public const WEB = 'web';

    public const APP = 'app';

    public const LIFF = 'liff';

    public const DRIVER = 'driver';

    /** ที่มาของใบจองที่ไม่ได้มาจาก client ของลูกค้าโดยตรง */
    public const ADMIN = 'admin';

    public const INTAKE = 'intake';

    /** ชื่อ token ก่อนแยกช่องทาง — ยังมีอยู่ในเครื่องลูกค้าจำนวนมาก */
    public const LEGACY_TOKEN = 'auth-token';

    public const DRIVER_TOKEN = 'driver-app-token';

    /** ช่องทางที่ออก token ของตัวเองได้ */
    private const TOKEN_CHANNELS = [self::WEB, self::APP, self::LIFF];

    /** ช่องทางที่ client ประกาศผ่าน X-Client ได้ */
    private const HEADER_CHANNELS = [self::WEB, self::APP, self::LIFF];

    /** ค่าที่ bookings.source เก็บได้ */
    public const BOOKING_SOURCES = [self::WEB, self::APP, self::LIFF, self::ADMIN, self::INTAKE];

    public static function fromRequest(Request $request): ?string
    {
        return self::fromToken(self::currentToken($request)) ?? self::fromHeaders($request);
    }

    public static function fromHeaders(Request $request): ?string
    {
        $client = strtolower(trim((string) $request->header('X-Client')));
        if (in_array($client, self::HEADER_CHANNELS, true)) {
            return $client;
        }

        return self::platform($request) ? self::APP : null;
    }

    public static function fromToken(?PersonalAccessToken $token): ?string
    {
        if (! $token) {
            return null;
        }

        if ($token->name === self::DRIVER_TOKEN) {
            return self::DRIVER;
        }

        return in_array($token->name, self::TOKEN_CHANNELS, true) ? $token->name : null;
    }

    /** ios|android จาก X-Platform — มีเฉพาะแอปที่ส่งมา */
    public static function platform(Request $request): ?string
    {
        $platform = strtolower(trim((string) $request->header('X-Platform')));

        return in_array($platform, ['ios', 'android'], true) ? $platform : null;
    }

    /** เวอร์ชันแอปจาก X-App-Version เช่น "1.8.2" หรือ "1.8.2+140" */
    public static function appVersion(Request $request): ?string
    {
        $version = trim((string) $request->header('X-App-Version'));

        return preg_match('/^\d+(\.\d+){0,3}(\+\d+)?$/', $version) ? $version : null;
    }

    /**
     * ออก token ให้ตามช่องทาง — `$channel` null (client ไม่บอกตัว) ได้ชื่อเดิม
     * `auth-token` ไม่หมดอายุ เพื่อไม่ให้แอปรุ่นเก่าที่ไม่ส่ง header หลุดออกจากระบบ
     */
    public static function issueToken(User $user, ?string $channel): string
    {
        if (! in_array($channel, self::TOKEN_CHANNELS, true)) {
            return $user->createToken(self::LEGACY_TOKEN)->plainTextToken;
        }

        $expiresAt = $channel === self::WEB ? now()->addDays(self::webTokenTtlDays()) : null;

        return $user->createToken($channel, ['*'], $expiresAt)->plainTextToken;
    }

    public static function webTokenTtlDays(): int
    {
        return max(1, (int) config('auth.web_token_ttl_days', 30));
    }

    /**
     * เลื่อนวันหมดอายุของ token เว็บที่ยังใช้งานอยู่ — เขียนลงฐานข้อมูลอย่างมากวันละครั้ง
     * ต่อ token (เลื่อนเฉพาะเมื่อเหลือไม่ถึง TTL - 1 วัน)
     */
    public static function extendWebToken(?PersonalAccessToken $token): void
    {
        if (! $token || $token->name !== self::WEB || ! $token->expires_at) {
            return;
        }

        $ttl = self::webTokenTtlDays();
        if ($token->expires_at->greaterThan(now()->addDays($ttl - 1))) {
            return;
        }

        $token->forceFill(['expires_at' => now()->addDays($ttl)])->save();
    }

    public static function currentToken(Request $request): ?PersonalAccessToken
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token : null;
    }
}
