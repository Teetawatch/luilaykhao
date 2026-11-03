<?php

namespace App\Models;

use App\Jobs\DeleteMediaFilesJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PassportPinPhoto extends Model
{
    protected $fillable = [
        'passport_pin_id', 'disk', 'path', 'thumb_path', 'url', 'thumb_url',
        'width', 'height', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (PassportPinPhoto $photo) {
            DeleteMediaFilesJob::dispatch($photo->storageDisk(), $photo->mediaPaths())
                ->afterCommit();
        });
    }

    public function pin(): BelongsTo
    {
        return $this->belongsTo(PassportPin::class, 'passport_pin_id');
    }

    public function storageDisk(): string
    {
        return $this->disk ?: config('filesystems.default');
    }

    public function mediaPaths(): array
    {
        return array_values(array_filter([$this->path, $this->thumb_path]));
    }

    public function getPublicUrlAttribute(): ?string
    {
        return $this->url ?: Storage::disk($this->storageDisk())->url($this->path);
    }

    public function getThumbPublicUrlAttribute(): ?string
    {
        if ($this->thumb_url) {
            return $this->thumb_url;
        }
        if ($this->thumb_path) {
            return Storage::disk($this->storageDisk())->url($this->thumb_path);
        }

        return $this->public_url;
    }
}
