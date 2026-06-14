<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use HasFactory;

    /**
     * Match the DB column default in-memory too, so freshly-created models
     * report 'active' before a refresh. Observers (e.g. the new-trip broadcast)
     * read `status` straight off the just-created instance, which would
     * otherwise be null when the caller omits it.
     */
    protected $attributes = [
        'status' => 'active',
    ];

    protected $fillable = [
        'title', 'slug', 'type', 'location', 'region', 'description',
        'difficulty', 'duration_days', 'max_participants',
        'price_per_person', 'departure_point', 'latitude', 'longitude',
        'status', 'cover_image', 'thumbnail_image', 'gallery', 'inclusions', 'exclusions', 'is_featured',
        'highlights', 'is_women_only', 'must_know', 'itinerary', 'preparations',
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
            'gallery' => 'array',
            'inclusions' => 'array',
            'exclusions' => 'array',
            'highlights' => 'array',
            'is_women_only' => 'boolean',
            'must_know' => 'array',
            'itinerary' => 'array',
            'preparations' => 'array',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TripSchedule::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(TripPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function getConfirmedPassengersCountAttribute(): int
    {
        return BookingPassenger::whereHas('booking', function ($q) {
            $q->whereIn('status', ['confirmed', 'completed'])
                ->whereHas('schedule', function ($sq) {
                    $sq->where('trip_id', $this->id);
                });
        })->count();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
