<?php

namespace App\Console\Commands;

use App\Support\ClientChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ยกเลิก token รุ่นเก่าชื่อ `auth-token` ทั้งหมด — ครั้งเดียวหลังแยก token ตามช่องทาง
 * (ClientChannel)
 *
 * token พวกนี้ไม่มีวันหมดอายุ และแยกไม่ออกว่าใบไหนอยู่ใน localStorage ของเว็บ
 * (ขโมยได้ถ้ามี XSS) ใบไหนอยู่ในที่เก็บปลอดภัยของแอป จึงต้องยกเลิกทั้งหมด ผลคือ
 * ทุกคนที่ล็อกอินค้างไว้ต้องล็อกอินใหม่หนึ่งครั้ง: แอปได้ 401 แล้วพากลับหน้าล็อกอิน
 * เอง, เว็บล้าง token แล้วไปหน้า /login, LIFF ขอ token ใหม่จาก LINE ตอนเปิดครั้งถัดไป
 * ล็อกอินใหม่จากเว็บ/แอปเวอร์ชันใหม่ได้ token ชื่อ web/app แทน
 *
 * ค่าเริ่มต้นแค่นับให้ดู ต้องใส่ --force ถึงจะลบจริง (ย้อนกลับไม่ได้) token ที่สร้าง
 * หลัง --before ไม่ถูกแตะ — แอปรุ่นเก่าที่ไม่ส่ง header ยังได้ `auth-token` ใหม่อยู่
 * รันซ้ำทีหลังจะเตะคนพวกนั้นออกซ้ำ จึงควรรันครั้งเดียว
 */
class RevokeLegacyTokensCommand extends Command
{
    protected $signature = 'auth:revoke-legacy-tokens
        {--before= : ยกเลิกเฉพาะ token ที่สร้างไม่เกินเวลานี้ (ค่าเริ่มต้น = ตอนนี้)}
        {--force : ลบจริง ไม่ใส่ = แค่นับให้ดู}';

    protected $description = 'Revoke every legacy "auth-token" Sanctum token so all clients re-login onto per-channel tokens.';

    public function handle(): int
    {
        try {
            $before = $this->option('before') ? Carbon::parse($this->option('before')) : now();
        } catch (\Throwable) {
            $this->error('อ่านค่า --before ไม่ออก ใช้รูปแบบเช่น "2026-11-12 03:00"');

            return self::FAILURE;
        }

        $query = fn () => PersonalAccessToken::query()
            ->where('name', ClientChannel::LEGACY_TOKEN)
            ->where('created_at', '<=', $before);

        $total = $query()->count();
        $users = $query()->distinct()->count('tokenable_id');
        $active = $query()->where('last_used_at', '>=', now()->subDays(30))->count();

        $this->line('');
        $this->line('  token ชื่อ '.ClientChannel::LEGACY_TOKEN." ที่สร้างก่อน {$before->toDateTimeString()}");
        $this->line("  ทั้งหมด            {$total}");
        $this->line("  ผู้ใช้ที่ได้รับผล     {$users}");
        $this->line("  ใช้ภายใน 30 วัน     {$active}  ← คนกลุ่มนี้จะต้องล็อกอินใหม่");
        $this->line('');

        if (! $this->option('force')) {
            $this->comment('ยังไม่ได้ลบอะไร — ใส่ --force เพื่อยกเลิกจริง (ย้อนกลับไม่ได้)');

            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            $ids = $query()->orderBy('id')->limit(1000)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : PersonalAccessToken::whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        Log::warning('Revoked legacy auth-token Sanctum tokens', [
            'deleted' => $deleted,
            'users' => $users,
            'before' => $before->toDateTimeString(),
        ]);

        $this->info("✓ ยกเลิกแล้ว {$deleted} token จากผู้ใช้ {$users} คน");

        return self::SUCCESS;
    }
}
