<?php

namespace App\Models;

use App\Models\Concerns\HasContentTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasContentTranslations, HasFactory;

    /** ช่องที่ระบบแปลให้ลูกค้าต่างชาติ (ดู ContentTranslationService) */
    protected array $translatableContent = ['name', 'display_title', 'subtitle', 'cta_text'];

    protected $fillable = [
        'name',
        'display_title',
        'subtitle',
        'cta_text',
        'slug',
        'icon',
        'image_url',
        'color',
        'bg_color',
        'is_popular',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_popular' => 'boolean',
        'order' => 'integer',
    ];

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'type', 'slug');
    }
}
