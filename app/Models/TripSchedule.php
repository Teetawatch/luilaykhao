<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\SchedulePickupPoint;

class TripSchedule extends Model
{
    use HasFactory;

    protected $table = 'trip_schedules';

    protected $fillable = [
        'trip_id', 'departure_date', 'return_date',
        'total_seats', 'booked_seats', 'transport_type',
        'vehicle_id', 'status', 'price_override',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'return_date' => 'date',
            'total_seats' => 'integer',
            'booked_seats' => 'integer',
            'price_override' => 'decimal:2',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'schedule_id');
    }

    public function bookingSeats(): HasMany
    {
        return $this->hasMany(BookingSeat::class, 'schedule_id');
    }

    public function pickupPoints(): HasMany
    {
        return $this->hasMany(SchedulePickupPoint::class, 'schedule_id')->orderBy('sort_order');
    }

    public function getAvailableSeatsAttribute(): int
    {
        return $this->total_seats - $this->booked_seats;
    }

    public function getEffectivePriceAttribute(): float
    {
        return $this->price_override ?? $this->trip->price_per_person;
    }
}
