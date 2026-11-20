<?php

namespace App\Console\Commands;

use App\Jobs\TranslateContentJob;
use App\Services\ContentTranslationService;
use Illuminate\Console\Command;

/**
 * แปลเนื้อหาที่มีอยู่แล้วทั้งหมด (ทริป จุดรับ หมวดหมู่ ประกาศ …) — รันครั้งเดียว
 * หลัง deploy ระบบแปล รันซ้ำได้: ช่องที่แปลแล้วและข้อความไทยไม่เปลี่ยนไม่เสียค่าแปลซ้ำ
 *
 * จุดรับถูกก๊อปไปทุกรอบ จึงแปลจุดรับของรอบที่ยังไม่เดินทางเท่านั้น (รอบเก่าไม่มี
 * ใครเปิดจองแล้ว) และข้อความไทยที่ซ้ำกันได้คำแปลเดิมไปใช้ ไม่เรียกโมเดลอีก
 */
class TranslateContentCommand extends Command
{
    protected $signature = 'content:translate
        {--type=* : เฉพาะชนิด เช่น trip, pickup-point (ไม่ใส่ = ทุกชนิด)}
        {--id= : เฉพาะแถวนี้ (ใช้คู่กับ --type)}
        {--force : แปลใหม่ทุกช่องที่ไม่ได้แก้มือ}
        {--sync : แปลในคำสั่งนี้เลย ไม่ส่งเข้าคิว}';

    protected $description = 'Translate admin-entered content (trips, pickup points, categories, …) into English with Claude.';

    public function handle(ContentTranslationService $translations): int
    {
        if (! ContentTranslationService::aiReady()) {
            $this->error('ยังไม่ได้ตั้ง ANTHROPIC_API_KEY — แปลไม่ได้');

            return self::FAILURE;
        }

        $types = $this->option('type') ?: array_keys(ContentTranslationService::TYPES);
        // รีวิวแปลเมื่อมีคนกดขอเท่านั้น — เป็นเสียงของลูกค้า ไม่แปลล่วงหน้า
        $types = array_values(array_diff($types, $this->option('type') ? [] : ['review']));

        $total = 0;
        foreach ($types as $type) {
            $class = ContentTranslationService::TYPES[$type] ?? null;
            if (! $class) {
                $this->error("ไม่รู้จักชนิด {$type}");

                return self::FAILURE;
            }

            $query = $class::query()->orderBy('id');
            if ($this->option('id')) {
                $query->whereKey((int) $this->option('id'));
            }
            $this->scopeForBackfill($type, $query);

            $count = 0;
            $query->each(function ($model) use ($translations, &$count) {
                if ($translations->segments($model) === []) {
                    return;
                }

                if ($this->option('sync')) {
                    try {
                        $result = $translations->sync($model, 'en', (bool) $this->option('force'));
                        $this->line(sprintf('  #%d แปล %d ใช้ซ้ำ %d', $model->getKey(), $result['translated'], $result['reused']));
                    } catch (\Throwable $e) {
                        $this->warn("  #{$model->getKey()} ไม่สำเร็จ: {$e->getMessage()}");
                    }
                } else {
                    TranslateContentJob::dispatch(ContentTranslationService::typeOf($model), (int) $model->getKey(), (bool) $this->option('force'));
                }
                $count++;
            });

            $this->info(($this->option('sync') ? 'แปลแล้ว ' : 'ส่งเข้าคิว ')."{$type}: {$count}");
            $total += $count;
        }

        $this->info("รวม {$total} รายการ");

        return self::SUCCESS;
    }

    /** รอบ/จุดรับ/ตัวเลือกรถ/ประกาศของรอบที่จบไปแล้วไม่ต้องแปล — ไม่มีใครเปิดจองแล้ว */
    private function scopeForBackfill(string $type, $query): void
    {
        if ($this->option('id')) {
            return;
        }

        $today = now()->toDateString();

        match ($type) {
            'schedule' => $query->where('departure_date', '>=', $today),
            'pickup-point', 'vehicle-option', 'announcement' => $query->whereHas(
                'schedule',
                fn ($q) => $q->where('departure_date', '>=', $today),
            ),
            default => null,
        };
    }
}
