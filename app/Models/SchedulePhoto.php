<?php

namespace App\Models;

use App\Jobs\DeleteMediaFilesJob;
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
            // Sweep the files after the row is gone — an R2 round-trip inside the
            // delete transaction is what used to make this 500 on a slow bucket.
            DeleteMediaFilesJob::dispatch($photo->storageDisk(), $photo->mediaPaths())
                ->afterCommit();
        });
    }

    /** The disk this photo's files live on. */
    public function storageDisk(): string
    {
        return $this->disk ?: config('filesystems.default');
    }

    /** Every stored object belonging to this photo. */
    public function mediaPaths(): array
    {
        return array_values(array_filter([$this->path, $this->thumb_path]));
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

        return Storage::disk($this->storageDisk())
            ->url($this->path);
    }

    public function getThumbPublicUrlAttribute(): ?string
    {
        if ($this->thumb_url) {
            return $this->thumb_url;
        }
        if ($this->thumb_path) {
            return Storage::disk($this->storageDisk())
                ->url($this->thumb_path);
        }

        return $this->public_url; // fall back to the full image
    }
}
