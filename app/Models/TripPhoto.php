<?php

namespace App\Models;

use App\Jobs\DeleteMediaFilesJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TripPhoto extends Model
{
    protected $fillable = [
        'trip_id', 'disk', 'path', 'thumb_path', 'url', 'thumb_url', 'original_name',
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

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
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
