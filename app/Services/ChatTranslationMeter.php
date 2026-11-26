<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * มิเตอร์ + เพดานรายเดือนของค่าแปลแชท (config/chat_translation.php)
 *
 * ทุกครั้งที่จะเรียกโมเดลต้องผ่าน [allow] ก่อน และเรียกแล้วต้อง [record] จาก
 * usage ที่ Anthropic ตอบมาจริง — ยอดเป็นบาทจึงตามบิลจริง ไม่ใช่การเดา
 * คำแปลที่ใช้ซ้ำจากข้อความเดียวกันในห้องอื่นนับไว้ใน [reused] (ไม่มีค่าใช้จ่าย)
 *
 * แบบเดียวกับ Maps\MapsUsageMeter — นับตามวัน/เดือนไทย
 */
class ChatTranslationMeter
{
    public const TIMEZONE = 'Asia/Bangkok';

    /** แคชยอดเดือนสั้น ๆ — allow() ถูกเรียกทุกข้อความ ไม่ต้อง SUM ทุกครั้ง */
    private const SPEND_CACHE_SECONDS = 60;

    /**
     * เรียกโมเดลได้ไหม — แปลอัตโนมัติหยุดก่อน (auto_stop_percent) เหลือที่ไว้ให้
     * คนที่กดแปลเอง ส่วนปุ่มกดแปลหยุดเมื่อครบ 100%
     */
    public function allow(bool $auto): bool
    {
        $percent = $this->percentOfCap();
        $limit = $auto ? (int) config('chat_translation.auto_stop_percent', 90) : 100;

        if ($percent < $limit) {
            return true;
        }

        $this->bump($this->model(), ['blocked' => 1]);

        $day = $this->today()->toDateString();
        if (Cache::add("chat_translate_cap_logged:{$day}:".($auto ? 'auto' : 'manual'), true, now()->addDay())) {
            Log::warning('Chat translation monthly budget reached', [
                'auto' => $auto,
                'spent_thb' => $this->monthSpendThb(),
                'cap_thb' => $this->capThb(),
            ]);
        }

        return false;
    }

    /** แปลอัตโนมัติถูกพักเพราะงบเดือนนี้ใกล้หมด */
    public function autoPaused(): bool
    {
        return $this->percentOfCap() >= (int) config('chat_translation.auto_stop_percent', 90);
    }

    /**
     * บันทึกการเรียกหนึ่งครั้งจาก usage ของคำตอบ
     *
     * @param  array<string, mixed>  $usage  response.usage
     */
    public function record(string $model, array $usage): void
    {
        $input = (int) ($usage['input_tokens'] ?? 0)
            + (int) ($usage['cache_creation_input_tokens'] ?? 0)
            + (int) ($usage['cache_read_input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);

        $this->bump($model, [
            'calls' => 1,
            'input_tokens' => $input,
            'output_tokens' => $output,
        ], $this->costUsd($model, $usage));

        Cache::forget($this->spendCacheKey());
    }

    /** ได้คำแปลจากข้อความเดียวกันในห้องอื่น — ไม่ได้เรียกโมเดล */
    public function reused(): void
    {
        $this->bump($this->model(), ['reused' => 1]);
    }

    /**
     * ราคาเป็น USD — cache write/read คิดตามอัตราของ Anthropic (1.25x / 0.1x)
     *
     * @param  array<string, mixed>  $usage
     */
    public function costUsd(string $model, array $usage): float
    {
        $prices = $this->prices($model);
        $in = $prices['input'] / 1_000_000;
        $out = $prices['output'] / 1_000_000;

        return (int) ($usage['input_tokens'] ?? 0) * $in
            + (int) ($usage['cache_creation_input_tokens'] ?? 0) * $in * 1.25
            + (int) ($usage['cache_read_input_tokens'] ?? 0) * $in * 0.1
            + (int) ($usage['output_tokens'] ?? 0) * $out;
    }

    public function capThb(): float
    {
        return (float) config('chat_translation.monthly_cap_thb', 1000);
    }

    public function monthSpendThb(): float
    {
        return (float) Cache::remember(
            $this->spendCacheKey(),
            self::SPEND_CACHE_SECONDS,
            fn () => round($this->usdBetween($this->today()->startOfMonth(), $this->today()) * $this->rate(), 4),
        );
    }

    public function percentOfCap(): float
    {
        $cap = $this->capThb();

        return $cap > 0 ? round($this->monthSpendThb() / $cap * 100, 1) : 0.0;
    }

    /**
     * สรุปเดือนนี้สำหรับหน้าแอดมิน /admin/support และ ops:doctor
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $today = $this->today();
        $rows = DB::table('chat_translation_usage')
            ->whereBetween('date', [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()])
            ->get();
        $rate = $this->rate();
        $todayRows = $rows->filter(fn ($r) => substr((string) $r->date, 0, 10) === $today->toDateString());

        return [
            'month' => $today->format('Y-m'),
            'model' => $this->model(),
            'spent_thb' => round((float) $rows->sum('cost_usd') * $rate, 2),
            'today_thb' => round((float) $todayRows->sum('cost_usd') * $rate, 2),
            'cap_thb' => $this->capThb(),
            'percent' => $this->percentOfCap(),
            'auto_stop_percent' => (int) config('chat_translation.auto_stop_percent', 90),
            'auto_paused' => $this->autoPaused(),
            'calls' => (int) $rows->sum('calls'),
            'reused' => (int) $rows->sum('reused'),
            'blocked' => (int) $rows->sum('blocked'),
            'usd_thb' => $rate,
            'pricing_checked_at' => config('chat_translation.pricing_checked_at'),
        ];
    }

    /** @return array{input: float, output: float} */
    private function prices(string $model): array
    {
        $table = config('chat_translation.usd_per_mtok', []);
        foreach ($table as $prefix => $prices) {
            if ($prefix !== 'unknown' && str_starts_with($model, $prefix)) {
                return ['input' => (float) $prices['input'], 'output' => (float) $prices['output']];
            }
        }

        $fallback = $table['unknown'] ?? ['input' => 5, 'output' => 25];

        return ['input' => (float) $fallback['input'], 'output' => (float) $fallback['output']];
    }

    private function rate(): float
    {
        return (float) config('chat_translation.usd_thb', 36);
    }

    private function model(): string
    {
        return (string) config('services.anthropic.translate_model', 'claude-haiku-5-5');
    }

    private function today(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->startOfDay();
    }

    private function spendCacheKey(): string
    {
        return 'chat_translate_spend:'.$this->today()->format('Y-m');
    }

    private function usdBetween(Carbon $from, Carbon $to): float
    {
        return (float) DB::table('chat_translation_usage')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('cost_usd');
    }

    /** @param  array<string, int>  $columns */
    private function bump(string $model, array $columns, float $costUsd = 0.0): void
    {
        $date = $this->today()->toDateString();
        $model = substr($model, 0, 64);

        try {
            DB::table('chat_translation_usage')->insertOrIgnore([
                'date' => $date,
                'model' => $model,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $updates = ['updated_at' => now()];
            foreach ($columns as $column => $by) {
                if ($by > 0) {
                    $updates[$column] = DB::raw("{$column} + ".(int) $by);
                }
            }
            if ($costUsd > 0) {
                $updates['cost_usd'] = DB::raw('cost_usd + '.sprintf('%.6F', $costUsd));
            }

            DB::table('chat_translation_usage')
                ->where('date', $date)
                ->where('model', $model)
                ->update($updates);
        } catch (\Throwable $e) {
            // มิเตอร์พังต้องไม่ทำให้แชทพัง — แต่ต้องรู้ เพราะเพดานจะนับไม่ครบ
            Log::warning('ChatTranslationMeter: could not record usage', ['error' => $e->getMessage()]);
        }
    }
}
