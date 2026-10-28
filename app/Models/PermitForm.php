<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * แบบฟอร์มขออนุญาตเข้าพื้นที่ (อุทยาน/หน่วยงาน) ที่ต้องกรอกต่อผู้เดินทางหนึ่งคน
 *
 * ทริปหลายทริปใช้แบบฟอร์มเดียวกันได้ (`trips.permit_form_id`) ดู PermitFormService
 *
 * @property array $pages [{path, width_mm, height_mm}]
 * @property array|null $fields [{id, page, source, x, y, w, h, size, align, text?}]
 */
class PermitForm extends Model
{
    protected $fillable = ['name', 'note', 'source_path', 'source_name', 'pages', 'fields'];

    protected function casts(): array
    {
        return [
            'pages' => 'array',
            'fields' => 'array',
        ];
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }
}
