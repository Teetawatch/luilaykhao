<?php

namespace App\Support;

use App\Models\ContentTranslation;
use App\Services\ContentTranslationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * คำแปลของ request นี้ — โหลดครั้งเดียวต่อโมเดล แล้วสวมลงค่าที่ API จะส่งออก
 *
 * ผูกแบบ scoped (AppServiceProvider) จึงล้างเองทุก request/ทุกงานในคิว
 * รายการยาว ๆ ให้เรียก [primeTree] ก่อนสร้าง resource จะได้คิวรีเดียวต่อชนิด
 * ไม่ใช่ทีละแถว
 */
class TranslationStore
{
    /** @var array<string, array<string, array<int, array{byPath: Collection, byHash: Collection}>>> locale → type → id */
    private array $rows = [];

    /**
     * สวมคำแปลลงค่าของช่อง (string หรือ array ซ้อน)
     *
     * หาคำแปลด้วยข้อความไทยเป็นหลัก ไม่ใช่ตำแหน่ง — ค่าที่ API ส่ง (เช่นอุปกรณ์เช่า
     * ที่จัดระเบียบแล้ว) เรียงต่างจากที่เก็บได้ ข้อความไทยเดียวกันได้คำแปลเดียวกัน
     * เสมอ ส่วนคำแปลที่แอดมินแก้เองแล้วต้นฉบับเปลี่ยนทีหลัง (ล็อกไว้) ยังใช้ตาม
     * ตำแหน่ง ช่องที่ยังแปลไม่เสร็จได้ข้อความไทยไปก่อน หน้าเว็บไม่มีวันว่าง
     */
    public function apply(Model $model, string $field, mixed $value, string $locale): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return $value;
        }

        $rows = $this->rowsFor($model, $locale);
        if ($rows['byPath']->isEmpty()) {
            return $value;
        }

        return ContentText::map($value, $field, function (string $thai, string $path) use ($rows) {
            $hash = ContentTranslation::hash($thai);
            $row = $rows['byPath']->get($path);

            if ($row && $row->source_hash === $hash) {
                return $row->text;
            }
            if ($hit = $rows['byHash']->get($hash)) {
                return $hit->text;
            }
            if ($row && $row->is_manual) {
                return $row->text;
            }

            return $thai;
        });
    }

    /** @return array{byPath: Collection, byHash: Collection} */
    public function rowsFor(Model $model, string $locale): array
    {
        $type = ContentTranslationService::typeOf($model);
        $id = (int) $model->getKey();

        if (! isset($this->rows[$locale][$type][$id])) {
            $this->load($type, [$id], $locale);
        }

        return $this->rows[$locale][$type][$id];
    }

    /**
     * โหลดคำแปลของโมเดลทั้งชุดล่วงหน้า + ทุก relation ที่โหลดมาแล้วซ้อนลงไป
     * (ทริป → รอบ → จุดรับ/ตัวเลือกรถ) — ไม่ทำอะไรเลยเมื่อ request เป็นภาษาไทย
     */
    public function primeTree(mixed $models, ?string $locale = null): void
    {
        $locale ??= ContentLocale::current();
        if ($locale === null) {
            return;
        }

        $pending = [];
        $this->collect($models, $pending, []);

        foreach ($pending as $type => $ids) {
            $missing = array_values(array_filter(
                array_keys($ids),
                fn (int $id) => ! isset($this->rows[$locale][$type][$id]),
            ));
            if ($missing !== []) {
                $this->load($type, $missing, $locale);
            }
        }
    }

    public function forget(Model $model): void
    {
        $type = ContentTranslationService::typeOf($model);
        foreach (array_keys($this->rows) as $locale) {
            unset($this->rows[$locale][$type][(int) $model->getKey()]);
        }
    }

    /**
     * @param  array<string, array<int, true>>  $pending
     * @param  array<int, true>  $seen  กันวนซ้ำเมื่อ relation ชี้กลับหาแม่
     */
    private function collect(mixed $models, array &$pending, array $seen): void
    {
        if ($models instanceof Model) {
            $models = [$models];
        }
        if (! is_iterable($models)) {
            return;
        }

        foreach ($models as $model) {
            if (! $model instanceof Model) {
                continue;
            }
            $objectId = spl_object_id($model);
            if (isset($seen[$objectId])) {
                continue;
            }
            $seen[$objectId] = true;

            if ($type = ContentTranslationService::typeOrNull($model)) {
                $pending[$type][(int) $model->getKey()] = true;
            }

            foreach ($model->getRelations() as $related) {
                $this->collect($related, $pending, $seen);
            }
        }
    }

    /** @param  list<int>  $ids */
    private function load(string $type, array $ids, string $locale): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $found = ContentTranslation::query()
                ->where('translatable_type', $type)
                ->whereIn('translatable_id', $chunk)
                ->where('locale', $locale)
                ->get()
                ->groupBy('translatable_id');

            foreach ($chunk as $id) {
                $rows = $found->get($id, collect());
                $this->rows[$locale][$type][$id] = [
                    'byPath' => $rows->keyBy('path'),
                    // คำแปลที่แอดมินแก้เองชนะคำแปล AI ของข้อความไทยเดียวกัน (keyBy เก็บตัวท้าย)
                    'byHash' => $rows->sortBy(fn ($row) => $row->is_manual ? 1 : 0)->keyBy('source_hash'),
                ];
            }
        }
    }
}
