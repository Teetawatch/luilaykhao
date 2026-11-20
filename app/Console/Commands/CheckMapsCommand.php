<?php

namespace App\Console\Commands;

use App\Services\Maps\GoogleRoutesClient;
use App\Services\Maps\MapsUsageMeter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * ตรวจว่าคีย์ Google Routes API ของเซิร์ฟเวอร์ใช้ได้จริง และถ้าไม่ได้ — เพราะอะไร
 *
 * GoogleRoutesClient คืน null เงียบ ๆ ทุกกรณีที่ใช้ไม่ได้ (ระบบถอยไปใช้ค่าประมาณ
 * จากเส้นตรงเอง) ซึ่งดีกับลูกค้าแต่แย่กับคนตั้งค่า: ไม่มีคีย์, config ถูก cache ไว้
 * ก่อนใส่คีย์, IP ไม่ตรง restriction, ลืมเปิด Routes API หรือยังไม่ผูก billing
 * ล้วนได้ null หน้าตาเดียวกัน คำสั่งนี้ยิงคำขอจริงหนึ่งครั้ง (นับเข้ามิเตอร์ด้วย)
 * แล้วบอกสาเหตุเป็นภาษาไทย
 */
class CheckMapsCommand extends Command
{
    protected $signature = 'maps:check';

    protected $description = 'Verify the server Google Routes API key with one real request and explain any failure.';

    public function handle(GoogleRoutesClient $routes, MapsUsageMeter $meter): int
    {
        $key = (string) config('services.google_maps.api_key', '');

        $this->line('');
        $this->line('  คีย์ (GOOGLE_MAPS_API_KEY)  '.($key === '' ? '— ไม่มี' : $this->mask($key)));
        $this->line('  config cache              '.(app()->configurationIsCached() ? 'cache อยู่ (แก้ .env แล้วต้อง config:cache ใหม่)' : 'ไม่ได้ cache'));
        $this->line('  ตาราง maps_api_usage       '.(Schema::hasTable('maps_api_usage') ? 'มีแล้ว' : 'ยังไม่มี — รัน php artisan migrate'));
        $this->line('');

        if ($key === '') {
            $this->error('✗ ไม่มีคีย์ — ระบบใช้ ETA ประมาณจากเส้นตรง (ไม่มีค่าใช้จ่าย)');
            $this->line(app()->configurationIsCached()
                ? '  config ถูก cache ไว้ก่อนใส่คีย์: รัน php artisan config:cache (หรือ deploy ใหม่) แล้วลองอีกครั้ง'
                : '  ใส่ GOOGLE_MAPS_API_KEY=AIza... ในไฟล์ .env ของเครื่องนี้ (เครื่อง local กับ production คนละไฟล์)');

            return self::FAILURE;
        }

        if ($key !== trim($key, " \t\"'") || ! str_starts_with($key, 'AIza')) {
            $this->warn('! คีย์หน้าตาแปลก (มีช่องว่าง/เครื่องหมายคำพูดติดมา หรือไม่ได้ขึ้นต้นด้วย AIza) — ตรวจบรรทัดใน .env');
        }

        if (! Schema::hasTable('maps_api_usage')) {
            $this->error('✗ ยังไม่ได้รัน migration — php artisan migrate');

            return self::FAILURE;
        }

        // ไม่คิดจราจร ไม่มีจุดแวะ = SKU Essentials (ฟรี 10,000/เดือน)
        $route = $routes->route(['lat' => 13.7563, 'lng' => 100.5018], ['lat' => 13.8023, 'lng' => 100.5536]);

        if ($route) {
            $this->info(sprintf(
                '✓ Routes API ใช้ได้ — ทดสอบสนามหลวง → หมอชิต %.1f กม. ~%d นาที',
                $route['distance'] / 1000,
                (int) round($route['duration'] / 60),
            ));
            $this->line('  ยอดใช้เดือนนี้ดูได้ที่หน้าแอดมิน "ติดตามรถ GPS" (คำขอทดสอบนี้นับด้วย 1 ครั้ง)');

            return self::SUCCESS;
        }

        $error = $routes->lastError() ?? ['reason' => 'unknown'];
        $this->error('✗ '.$this->explain($error));

        if (! empty($error['message'])) {
            $this->line('  Google ตอบ: '.($error['http'] ?? '').' '.($error['status'] ?? '').' — '.$error['message']);
        }

        return self::FAILURE;
    }

    /** @param  array<string, mixed>  $error */
    private function explain(array $error): string
    {
        $message = strtolower((string) ($error['message'] ?? ''));

        return match (true) {
            $error['reason'] === 'cap_reached' => 'ชนเพดานการใช้ของ '.$error['sku'].' (config/maps.php caps) — ระบบตั้งใจหยุดยิงเพื่อไม่ให้เสียเงิน',
            $error['reason'] === 'connection' => 'เชื่อมต่อ routes.googleapis.com ไม่ได้ — ตรวจเน็ต/ไฟร์วอลล์ขาออกของเครื่องนี้',
            $error['reason'] === 'no_route' => 'Google ตอบแต่ไม่มีเส้นทาง (ผิดปกติสำหรับพิกัดทดสอบ) — ลองใหม่อีกครั้ง',
            str_contains($message, 'ip address') => 'IP ของเครื่องนี้ไม่อยู่ใน Application restrictions ของคีย์ — เพิ่ม IP ที่ Google บอกด้านล่าง (ทั้ง IPv4/IPv6) ใน Cloud Console → Credentials',
            str_contains($message, 'has not been used') || str_contains($message, 'is disabled') => 'ยังไม่ได้เปิด Routes API ในโปรเจกต์ของคีย์นี้ — APIs & Services → Library → Routes API → Enable (เพิ่งเปิดต้องรอ 2–5 นาที)',
            str_contains($message, 'billing') => 'โปรเจกต์ยังไม่ได้ผูก Billing — Google บังคับแม้ใช้อยู่ในโควตาฟรี',
            str_contains($message, 'blocked') => 'API restrictions ของคีย์ไม่ได้ติ๊ก Routes API — แก้ที่ Credentials → คีย์นี้ → API restrictions',
            str_contains($message, 'api key not valid') || str_contains($message, 'api_key_invalid') => 'คีย์ไม่ถูกต้อง — คัดลอกใหม่จาก Credentials (ระวังช่องว่าง/ตัวอักษรขาด)',
            ($error['http'] ?? 0) === 429 => 'เกินโควตาต่อนาทีของ Google — รอสักครู่แล้วลองใหม่',
            $error['reason'] === 'http' => 'Google ปฏิเสธคำขอ (HTTP '.$error['http'].')',
            default => 'ใช้ไม่ได้ด้วยเหตุผลที่ไม่รู้จัก — ดู storage/logs/laravel.log หา "Google Routes API"',
        };
    }

    private function mask(string $key): string
    {
        return strlen($key) <= 10
            ? str_repeat('•', strlen($key))
            : substr($key, 0, 4).'…'.substr($key, -4).' ('.strlen($key).' ตัวอักษร)';
    }
}
