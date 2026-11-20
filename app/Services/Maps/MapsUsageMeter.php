<?php

namespace App\Services\Maps;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * มิเตอร์ + เพดานการเรียก Google Maps Platform
 *
 * ทุกคำขอที่จะยิงออกไปต้องผ่าน [allow] ก่อน: เกินเพดานรายวันหรือรายเดือน
 * (config/maps.php `caps`) = ไม่ยิง ผู้เรียกถอยไปใช้ค่าประมาณเอง ยิงแล้วต้อง
 * [record] ทุกครั้งเพื่อให้ยอดตรงกับที่ Google จะคิดเงิน
 *
 * นับตามวัน/เดือนไทย ส่วน Google ตัดรอบเดือนตามเวลาแปซิฟิก — ต่างกันไม่กี่
 * ชั่วโมงต้นเดือน เพดานเริ่มต้นจึงตั้งเท่าโควตาฟรีพอดีแต่ยังมีวันละเพดานกันไว้อีกชั้น
 */
class MapsUsageMeter
{
    public const TIMEZONE = 'Asia/Bangkok';

    public const ROUTES_ESSENTIALS = 'routes_essentials';

    public const ROUTES_PRO = 'routes_pro';

    public const MATRIX_ESSENTIALS = 'matrix_essentials';

    /** ยิงได้ไหม — ไม่ได้ก็นับเป็น blocked และเตือนใน log วันละครั้งต่อ SKU */
    public function allow(string $sku, int $units = 1): bool
    {
        $caps = config("maps.caps.{$sku}", []);
        $daily = (int) ($caps['daily'] ?? 0);
        $monthly = (int) ($caps['monthly'] ?? 0);

        $today = $this->today();
        $usedToday = $this->unitsBetween($sku, $today, $today);
        $usedMonth = $this->unitsBetween($sku, $today->copy()->startOfMonth(), $today);

        $overDaily = $daily > 0 && $usedToday + $units > $daily;
        $overMonthly = $monthly > 0 && $usedMonth + $units > $monthly;

        if (! $overDaily && ! $overMonthly) {
            return true;
        }

        $this->bump($sku, ['blocked' => 1]);

        if (Cache::add("maps_cap_logged:{$sku}:{$today->toDateString()}", true, now()->addDay())) {
            Log::warning('Google Maps usage cap reached — falling back to estimates', [
                'sku' => $sku,
                'used_today' => $usedToday,
                'daily_cap' => $daily,
                'used_month' => $usedMonth,
                'monthly_cap' => $monthly,
            ]);
        }

        return false;
    }

    /** คำขอที่ยิงออกไปแล้ว — Google คิดเงินแม้คำตอบจะเป็น error ฝั่งข้อมูล */
    public function record(string $sku, int $units = 1, bool $ok = true): void
    {
        $this->bump($sku, [
            'units' => max(1, $units),
            'requests' => 1,
            'failures' => $ok ? 0 : 1,
        ]);
    }

    /** คำตอบที่ได้จากแคช = เงินที่ประหยัดได้ (เก็บใน Redis พอ ไม่ต้องแม่นระดับบิล) */
    public function cacheHit(string $sku): void
    {
        try {
            $key = 'maps_cache_hits:'.$this->today()->toDateString().':'.$sku;
            Cache::add($key, 0, now()->addDays(40));
            Cache::increment($key);
        } catch (\Throwable) {
            // สถิติอย่างเดียว พังได้ ไม่กระทบคำตอบ
        }
    }

    /**
     * สรุปของเดือนสำหรับหน้าแอดมิน
     *
     * @return array<string, mixed>
     */
    public function summary(?Carbon $month = null): array
    {
        $today = $this->today();
        $month = ($month ?? $today)->copy()->startOfMonth();
        $end = $month->isSameMonth($today) ? $today : $month->copy()->endOfMonth();

        $rows = DB::table('maps_api_usage')
            ->whereBetween('date', [$month->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->get();

        $skus = [];
        foreach (config('maps.skus', []) as $sku => $info) {
            $mine = $rows->where('sku', $sku);
            $used = (int) $mine->sum('units');
            $free = (int) ($info['free'] ?? 0);
            $caps = config("maps.caps.{$sku}", []);
            $billable = max(0, $used - $free);

            $cacheHits = 0;
            for ($d = $month->copy(); $d->lte($end); $d->addDay()) {
                $cacheHits += (int) Cache::get("maps_cache_hits:{$d->toDateString()}:{$sku}", 0);
            }

            $skus[] = [
                'sku' => $sku,
                'label' => $info['label'] ?? $sku,
                'units' => $used,
                'requests' => (int) $mine->sum('requests'),
                'failures' => (int) $mine->sum('failures'),
                'blocked' => (int) $mine->sum('blocked'),
                'cache_hits' => $cacheHits,
                'today' => (int) $mine->where('date', $today->toDateString())->sum('units'),
                'free_cap' => $free,
                'monthly_cap' => (int) ($caps['monthly'] ?? 0),
                'daily_cap' => (int) ($caps['daily'] ?? 0),
                'percent_of_free' => $free > 0 ? round($used / $free * 100, 1) : null,
                'estimated_usd' => round($billable / 1000 * (float) ($info['usd_per_1000'] ?? 0), 2),
            ];
        }

        $daily = $rows->groupBy(fn ($r) => substr((string) $r->date, 0, 10))
            ->map(fn ($group, $date) => [
                'date' => $date,
                'units' => $group->mapWithKeys(fn ($r) => [$r->sku => (int) $r->units])->all(),
                'blocked' => (int) $group->sum('blocked'),
            ])
            ->values()
            ->all();

        return [
            'month' => $month->format('Y-m'),
            'skus' => $skus,
            'daily' => $daily,
            'estimated_usd' => round(array_sum(array_column($skus, 'estimated_usd')), 2),
            'pricing_checked_at' => config('maps.pricing_checked_at'),
        ];
    }

    /** % ของเพดานรายเดือนที่ใช้ไปแล้ว (สูงสุดของทุก SKU) — สำหรับ ops:doctor */
    public function highestMonthlyPercent(): array
    {
        $today = $this->today();
        $worst = ['sku' => null, 'percent' => 0.0, 'used' => 0, 'cap' => 0];

        foreach (config('maps.caps', []) as $sku => $caps) {
            $cap = (int) ($caps['monthly'] ?? 0);
            if ($cap <= 0) {
                continue;
            }
            $used = $this->unitsBetween($sku, $today->copy()->startOfMonth(), $today);
            $percent = round($used / $cap * 100, 1);
            if ($percent > $worst['percent']) {
                $worst = ['sku' => $sku, 'percent' => $percent, 'used' => $used, 'cap' => $cap];
            }
        }

        return $worst;
    }

    private function unitsBetween(string $sku, Carbon $from, Carbon $to): int
    {
        return (int) DB::table('maps_api_usage')
            ->where('sku', $sku)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('units');
    }

    /** @param  array<string, int>  $columns */
    private function bump(string $sku, array $columns): void
    {
        $date = $this->today()->toDateString();

        try {
            DB::table('maps_api_usage')->insertOrIgnore([
                'date' => $date,
                'sku' => $sku,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $updates = ['updated_at' => now()];
            foreach ($columns as $column => $by) {
                if ($by > 0) {
                    $updates[$column] = DB::raw("{$column} + ".(int) $by);
                }
            }

            DB::table('maps_api_usage')
                ->where('date', $date)
                ->where('sku', $sku)
                ->update($updates);
        } catch (\Throwable $e) {
            Log::warning('Could not record Google Maps usage', ['sku' => $sku, 'error' => $e->getMessage()]);
        }
    }

    private function today(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->startOfDay();
    }
}
