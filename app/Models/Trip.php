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
        'views_count' => 0,
    ];

    protected $fillable = [
        'title', 'slug', 'type', 'location', 'region', 'description',
        'difficulty', 'duration_days', 'distance_km', 'elevation_gain_m', 'max_participants',
        'price_per_person', 'departure_point', 'latitude', 'longitude',
        'status', 'cover_image', 'thumbnail_image', 'gallery', 'videos', 'inclusions', 'exclusions', 'is_featured',
        'highlights', 'is_women_only', 'must_know', 'itinerary', 'preparations', 'faqs', 'rental_items',
        'route_track',
    ];

    protected function casts(): array
    {
        return [
            'price_per_person' => 'decimal:2',
            'duration_days' => 'integer',
            'distance_km' => 'decimal:2',
            'elevation_gain_m' => 'integer',
            'route_track' => 'array',
            'max_participants' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'is_featured' => 'boolean',
            'views_count' => 'integer',
            'gallery' => 'array',
            'videos' => 'array',
            'inclusions' => 'array',
            'exclusions' => 'array',
            'highlights' => 'array',
            'is_women_only' => 'boolean',
            'must_know' => 'array',
            'itinerary' => 'array',
            'preparations' => 'array',
            'faqs' => 'array',
            'rental_items' => 'array',
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

    public function expenseTemplates(): HasMany
    {
        return $this->hasMany(ExpenseTemplate::class)->orderBy('sort_order')->orderBy('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(TripPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function getConfirmedPassengersCountAttribute(): int
    {
        return BookingPassenger::whereHas('booking', function ($q) {
            $q->where('status', 'completed')
                ->whereHas('schedule', function ($sq) {
                    $sq->where('trip_id', $this->id);
                });
        })->count();
    }

    /**
     * Total successful bookings (confirmed or completed) across this trip's
     * schedules. Powers the home "ยอดการจอง" trust stat.
     */
    public function getBookingsCountAttribute(): int
    {
        return Booking::whereIn('status', ['confirmed', 'completed'])
            ->whereHas('schedule', function ($sq) {
                $sq->where('trip_id', $this->id);
            })->count();
    }

    /**
     * Head-count of travellers across this trip's successful bookings
     * (confirmed or completed) — i.e. how many people have booked, not how
     * many booking records exist. Powers the home "คนจองแล้ว" trust stat.
     */
    public function getBookedPassengersCountAttribute(): int
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
