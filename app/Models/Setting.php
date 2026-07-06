<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Simple key-value store for site-wide toggles/config that admins edit at
 * runtime (e.g. the urgent-trips popup). Values are JSON, so a key can hold
 * a scalar or a whole settings block.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::remember(
            "setting:{$key}",
            now()->addMinutes(10),
            fn () => static::where('key', $key)->value('value')
        );

        return $value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting:{$key}");
    }
}
