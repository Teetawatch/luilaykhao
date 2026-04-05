<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPassenger extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id', 'name', 'nickname', 'id_card', 'phone', 'health_notes',
        'emergency_contact', 'emergency_phone',
        'dive_cert_level', 'cert_number', 'weight',
        'blood_group', 'allergies',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'health_notes' => 'encrypted',
            'id_card' => 'encrypted',
            'allergies' => 'encrypted',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
