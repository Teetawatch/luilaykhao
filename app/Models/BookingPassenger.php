<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingPassenger extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id', 'title', 'name', 'nickname', 'id_card', 'birth_date', 'phone', 'email', 'health_notes',
        'name_en', 'nationality', 'passport_no', 'passport_expires_at',
        'emergency_contact', 'emergency_phone',
        'dive_cert_level', 'cert_number', 'weight',
        'blood_group', 'allergies', 'halal_food', 'pickup_point_id',
        'self_fill_token', 'self_fill_expires_at', 'self_filled_at',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'birth_date' => 'date',
            'health_notes' => 'encrypted',
            'id_card' => 'encrypted',
            'passport_no' => 'encrypted',
            'passport_expires_at' => 'date',
            'allergies' => 'encrypted',
            'halal_food' => 'boolean',
            'self_fill_expires_at' => 'datetime',
            'self_filled_at' => 'datetime',
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

    /** เอกสารที่ผู้เดินทางคนนี้แนบมา */
    public function documents(): HasMany
    {
        return $this->hasMany(BookingDocument::class, 'booking_passenger_id');
    }
}
