<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class SchedulePhoto extends Model
{
    protected $fillable = [
        'disk', 'path', 'thumb_path', 'url', 'thumb_url', 'original_name',
        'mime', 'size', 'width', 'height',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (SchedulePhoto $photo) {
            $disk = Storage::disk($photo->disk ?: config('filesystems.default'));
            foreach (array_filter([$photo->path, $photo->thumb_path]) as $path) {
                $disk->delete($path);
            }
        });
    }

    public function schedules(): BelongsToMany
    {
        return $this->belongsToMany(TripSchedule::class, 'schedule_photo', 'photo_id', 'schedule_id')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function getPublicUrlAttribute(): ?string
    {
        if ($this->url) {
            return $this->url;
        }

        return Storage::disk($this->disk ?: config('filesystems.default'))
            ->url($this->path);
    }

    public function getThumbPublicUrlAttribute(): ?string
    {
        if ($this->thumb_url) {
            return $this->thumb_url;
        }
        if ($this->thumb_path) {
            return Storage::disk($this->disk ?: config('filesystems.default'))
                ->url($this->thumb_path);
        }

        return $this->public_url; // fall back to the full image
    }
}
