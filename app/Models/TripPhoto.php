<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TripPhoto extends Model
{
    protected $fillable = [
        'trip_id', 'disk', 'path', 'url', 'original_name',
        'mime', 'size', 'width', 'height', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (TripPhoto $photo) {
            if ($photo->path) {
                Storage::disk($photo->disk ?: config('filesystems.default'))
                    ->delete($photo->path);
            }
        });
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function getPublicUrlAttribute(): ?string
    {
        if ($this->url) {
            return $this->url;
        }

        return Storage::disk($this->disk ?: config('filesystems.default'))
            ->url($this->path);
    }
}
