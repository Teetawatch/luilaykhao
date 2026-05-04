<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'installment_enabled', 'installment_count', 'installment_interval_days',
        'join_trip_enabled', 'join_trip_price',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'return_date' => 'date',
            'total_seats' => 'integer',
            'booked_seats' => 'integer',
            'price_override'            => 'decimal:2',
            'installment_enabled'       => 'boolean',
            'installment_count'         => 'integer',
            'installment_interval_days' => 'integer',
            'join_trip_enabled'         => 'boolean',
            'join_trip_price'           => 'decimal:2',
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

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'schedule_staff_assignments', 'schedule_id', 'user_id')
            ->withPivot(['assigned_by', 'created_at'])
            ->withTimestamps();
    }

    public function getAvailableSeatsAttribute(): int
    {
        return $this->total_seats - $this->booked_seats;
    }

    public function getEffectivePriceAttribute(): float
    {
        return $this->price_override ?? $this->trip->price_per_person;
    }

    /**
     * Recalculate and sync the booked_seats counter from actual bookings.
     */
    public function syncBookedSeats(): int
    {
        $count = \App\Models\BookingPassenger::whereHas('booking', function($q) {
            $q->where('schedule_id', $this->id)
              ->whereIn('status', ['pending', 'confirmed']);
        })->count();
        
        $this->update(['booked_seats' => $count]);
        return $count;
    }
}
