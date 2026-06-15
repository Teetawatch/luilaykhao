<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPassenger extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id', 'title', 'name', 'nickname', 'id_card', 'birth_date', 'phone', 'email', 'health_notes',
        'emergency_contact', 'emergency_phone',
        'dive_cert_level', 'cert_number', 'weight',
        'blood_group', 'allergies', 'halal_food', 'pickup_point_id',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'birth_date' => 'date',
            'health_notes' => 'encrypted',
            'id_card' => 'encrypted',
            'allergies' => 'encrypted',
            'halal_food' => 'boolean',
        ];
    }

    /** Age in whole years, computed live from birth_date; null when unknown. */
    public function getAgeAttribute(): ?int
    {
        return $this->birth_date?->age;
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function pickupPoint(): BelongsTo
    {
        return $this->belongsTo(SchedulePickupPoint::class, 'pickup_point_id');
    }
}
