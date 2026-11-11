<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\User;
use App\Support\ClientChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * เว็บ แอป และ LIFF ใช้ API ชุดเดียวกัน — ก่อนจะเปลี่ยนรูปแบบ response หรือเลิก
 * รองรับ field เก่า ต้องรู้ว่าแอปรุ่นไหนยังมีคนเปิดอยู่ (users.app_version ที่
 * TrackApiClient บันทึกจาก X-App-Version) และแต่ละช่องทางมีคนใช้แค่ไหน
 */
class AppVersionsCommand extends Command
{
    protected $signature = 'app:versions {--days=30 : นับเฉพาะคนที่เปิดแอปภายในกี่วัน}';

    protected $description = 'Show which app versions customers still run, plus tokens and bookings per channel.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $this->line('');
        $this->info("แอปที่เปิดภายใน {$days} วัน (users.app_version)");
        $rows = User::query()
            ->whereNotNull('app_version')
            ->where('app_seen_at', '>=', $since)
            ->select('app_platform', 'app_version', DB::raw('COUNT(*) as users'), DB::raw('MAX(app_seen_at) as last_seen'))
            ->groupBy('app_platform', 'app_version')
            ->get()
            ->sortByDesc(fn ($row) => [$row->app_platform, $this->versionKey($row->app_version)])
            ->map(fn ($row) => [$row->app_platform, $row->app_version, $row->users, $row->last_seen])
            ->values()
            ->all();
        $rows === []
            ? $this->line('  ยังไม่มีข้อมูล — แอปรุ่นที่ส่ง X-App-Version ยังไม่มีใครเปิด')
            : $this->table(['แพลตฟอร์ม', 'เวอร์ชัน', 'ผู้ใช้', 'เห็นล่าสุด'], $rows);

        $this->line('');
        $this->info('token ที่ยังใช้งานได้ แยกตามช่องทาง');
        $tokens = PersonalAccessToken::query()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->select('name', DB::raw('COUNT(*) as total'), DB::raw("SUM(CASE WHEN last_used_at >= '".$since->toDateTimeString()."' THEN 1 ELSE 0 END) as active"))
            ->groupBy('name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [$this->tokenLabel($row->name), $row->total, (int) $row->active])
            ->all();
        $this->table(['ช่องทาง (ชื่อ token)', 'ทั้งหมด', "ใช้ภายใน {$days} วัน"], $tokens);

        $this->line('');
        $this->info("ใบจองภายใน {$days} วัน แยกตามช่องทาง (bookings.source)");
        $bookings = Booking::query()
            ->where('created_at', '>=', $since)
            ->select('source', DB::raw('COUNT(*) as total'))
            ->groupBy('source')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [$row->source ?? 'ไม่ทราบ', $row->total])
            ->all();
        $this->table(['ช่องทาง', 'ใบจอง'], $bookings);

        return self::SUCCESS;
    }

    private function tokenLabel(string $name): string
    {
        return match ($name) {
            ClientChannel::LEGACY_TOKEN => $name.' (รุ่นเก่า ไม่รู้ช่องทาง)',
            ClientChannel::DRIVER_TOKEN => $name.' (แอปคนขับ)',
            default => $name,
        };
    }

    /** "1.10.0+3" มาก่อน "1.9.9" — เรียงแบบตัวเลขทีละช่อง ไม่ใช่ตัวอักษร */
    private function versionKey(?string $version): string
    {
        $parts = preg_split('/[.+]/', (string) $version);

        return implode('.', array_map(fn ($p) => str_pad((string) (int) $p, 6, '0', STR_PAD_LEFT), $parts));
    }
}
