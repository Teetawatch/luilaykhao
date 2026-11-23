<?php

namespace App\Models\Concerns;

use App\Support\PersonName;

/**
 * ชื่อ / นามสกุลที่ลูกค้ากรอกแยกช่อง คู่กับชื่อเต็ม (`name`, `name_en`)
 *
 * - ส่งชื่อ/นามสกุลมา → ชื่อเต็มถูกประกอบใหม่จากสองช่องนี้เสมอ
 * - แก้แค่ชื่อเต็ม (แอปรุ่นเก่า, แอดมิน, ลิงก์กรอกเอง, รับต่อที่นั่ง) และไม่ตรงกับ
 *   สองช่องเดิมแล้ว → ล้างสองช่องทิ้ง กลับไปแยกจากช่องว่างแรกแทน ดีกว่าเก็บ
 *   ชื่อ/นามสกุลของคนเดิมค้างไว้คู่กับชื่อของอีกคน
 */
trait HasNameParts
{
    /** @return list<array{0: string, 1: string, 2: string}> [ชื่อเต็ม, ชื่อ, นามสกุล] */
    protected static function namePartColumns(): array
    {
        return [
            ['name', 'first_name', 'last_name'],
            ['name_en', 'first_name_en', 'last_name_en'],
        ];
    }

    public static function bootHasNameParts(): void
    {
        static::saving(fn (self $model) => $model->syncNameParts());
    }

    public function syncNameParts(): void
    {
        foreach (static::namePartColumns() as [$full, $firstColumn, $lastColumn]) {
            $first = PersonName::normalize($this->getAttribute($firstColumn));
            $last = PersonName::normalize($this->getAttribute($lastColumn));
            $hasParts = $first !== '' || $last !== '';

            if ($hasParts && ($this->isDirty($firstColumn) || $this->isDirty($lastColumn))) {
                $this->setAttribute($firstColumn, $first !== '' ? $first : null);
                $this->setAttribute($lastColumn, $last !== '' ? $last : null);
                $this->setAttribute($full, PersonName::join($first, $last));

                continue;
            }

            if ($hasParts && $this->isDirty($full)
                && mb_strtolower(PersonName::normalize($this->getAttribute($full)))
                    !== mb_strtolower(PersonName::join($first, $last))) {
                $this->setAttribute($firstColumn, null);
                $this->setAttribute($lastColumn, null);
            }
        }
    }

    /** @return array{0: string, 1: string} [ชื่อ, นามสกุล] */
    public function nameParts(): array
    {
        return $this->partsOf('name', 'first_name', 'last_name');
    }

    /** @return array{0: string, 1: string} [ชื่อ, นามสกุล] ภาษาอังกฤษตามพาสปอร์ต */
    public function nameEnParts(): array
    {
        return $this->partsOf('name_en', 'first_name_en', 'last_name_en');
    }

    /** @return array{0: string, 1: string} */
    private function partsOf(string $full, string $firstColumn, string $lastColumn): array
    {
        $first = PersonName::normalize($this->getAttribute($firstColumn));
        $last = PersonName::normalize($this->getAttribute($lastColumn));

        return $first !== '' || $last !== ''
            ? [$first, $last]
            : PersonName::split($this->getAttribute($full));
    }
}
