<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ชุดสติกเกอร์ — แอปแสดงเป็นแท็บในแผงสติกเกอร์ เรียงตาม sort_order
 */
class StickerPack extends Model
{
    protected $fillable = ['name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function stickers(): HasMany
    {
        return $this->hasMany(Sticker::class, 'pack_id')->orderBy('sort_order')->orderBy('id');
    }
}
