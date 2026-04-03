<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'slug', 'type', 'location', 'description',
        'difficulty', 'duration_days', 'max_participants',
        'price_per_person', 'departure_point', 'latitude', 'longitude',
        'status', 'cover_image', 'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'price_per_person' => 'decimal:2',
            'duration_days' => 'integer',
            'max_participants' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'is_featured' => 'boolean',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TripSchedule::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
