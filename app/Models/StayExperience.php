<?php

namespace App\Models;

use App\Support\MediaDisk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** กิจกรรมชุมชนที่จองพ่วงกับที่พักได้ */
class StayExperience extends Model
{
    protected $fillable = [
        'stay_id', 'title', 'description', 'price_per_person', 'duration_label', 'image', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_per_person' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function imageUrl(): ?string
    {
        return MediaDisk::url($this->image);
    }
}
