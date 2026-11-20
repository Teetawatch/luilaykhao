<?php

namespace App\Models\Concerns;

use App\Jobs\TranslateContentJob;
use App\Models\ContentTranslation;
use App\Services\ContentTranslationService;
use App\Support\ContentLocale;
use App\Support\TranslationStore;

/**
 * เนื้อหาที่แอดมินกรอกเป็นภาษาไทยแล้วระบบแปลให้ (ดู ContentTranslationService)
 *
 * โมเดลที่ใช้ trait นี้ประกาศ `protected array $translatableContent = [...]` คือช่อง
 * ที่ต้องแปล บันทึกเมื่อไหร่ที่ช่องพวกนั้นเปลี่ยน งานแปลเข้าคิวเอง และ API
 * อ่านผ่าน `$model->tr('field')` ได้ข้อความในภาษาของ request (ไทย = ค่าเดิม)
 *
 * `protected bool $translateOnSave = false` = แปลเมื่อมีคนกดขอเท่านั้น (รีวิว)
 */
trait HasContentTranslations
{
    public static function bootHasContentTranslations(): void
    {
        static::saved(function (self $model) {
            if (! $model->shouldTranslateOnSave()) {
                return;
            }

            $fields = $model->translatableFields();
            $changed = $model->wasRecentlyCreated
                ? collect($fields)->contains(fn (string $field) => filled($model->getAttribute($field)))
                : $model->wasChanged($fields);

            if ($changed) {
                TranslateContentJob::dispatchFor($model);
            }
        });

        static::deleted(function (self $model) {
            ContentTranslation::query()
                ->where('translatable_type', ContentTranslationService::typeOf($model))
                ->where('translatable_id', $model->getKey())
                ->delete();
        });
    }

    /** @return list<string> */
    public function translatableFields(): array
    {
        return $this->translatableContent ?? [];
    }

    public function shouldTranslateOnSave(): bool
    {
        return ($this->translateOnSave ?? true)
            && $this->translatableFields() !== []
            && config('services.anthropic.content_translate_on_save', true);
    }

    /**
     * ค่าที่ส่งไปแปล — ปกติคือค่าที่เก็บ โมเดลที่ API ส่งรูปที่จัดระเบียบแล้ว
     * (อุปกรณ์เช่า มื้ออาหาร) override ให้ตรงกับที่ลูกค้าเห็น
     */
    public function translationSource(string $field): mixed
    {
        return $this->getAttribute($field);
    }

    /**
     * ค่าของช่องในภาษาของ request — ไทยหรือหน้าแอดมินได้ค่าเดิมโดยไม่คิวรีอะไร
     * ส่ง $value มาเมื่อค่าที่จะออก API ผ่านการจัดรูปมาแล้ว (ไม่ใช่ attribute ตรง ๆ)
     */
    public function tr(string $field, mixed $value = null): mixed
    {
        $value = func_num_args() > 1 ? $value : $this->getAttribute($field);
        $locale = ContentLocale::current();

        if ($locale === null || $this->getKey() === null) {
            return $value;
        }

        return app(TranslationStore::class)->apply($this, $field, $value, $locale);
    }
}
