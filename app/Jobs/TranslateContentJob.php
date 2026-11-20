<?php

namespace App\Jobs;

use App\Services\ContentTranslationService;
use App\Support\ContentLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * แปลเนื้อหาของโมเดลหนึ่งตัวให้ทันกับข้อความไทยล่าสุด (ดู ContentTranslationService)
 *
 * ไม่ซ้ำกันต่อโมเดลจนกว่างานจะเริ่ม — แอดมินกดบันทึกรัว ๆ ได้งานเดียว และงาน
 * อ่านข้อความล่าสุดตอนเริ่มทำ ไม่ใช่ตอนที่ถูกสั่ง
 */
class TranslateContentJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct(
        public string $type,
        public int $id,
        public bool $force = false,
    ) {}

    public static function dispatchFor(Model $model, bool $force = false): void
    {
        // ไม่มี key = ไม่มีทางแปลได้ ไม่ต้องเข้าคิวให้เสียรอบ (เครื่อง dev / เทสต์)
        if (! ContentTranslationService::aiReady()) {
            return;
        }

        static::dispatch(ContentTranslationService::typeOf($model), (int) $model->getKey(), $force)->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->type.':'.$this->id;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ContentTranslationService $translations): void
    {
        $model = $translations->find($this->type, $this->id);
        if (! $model) {
            return;
        }

        foreach (ContentLocale::LOCALES as $locale) {
            $translations->sync($model, $locale, $this->force);
        }
    }
}
