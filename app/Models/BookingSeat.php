<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingSeat extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id', 'schedule_id', 'vehicle_option_id', 'seat_id', 'passenger_name',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    /**
     * คันที่ที่นั่งนี้อยู่ — 0 คือรอบที่มีรถคันเดียว (ไม่ใช่แถวจริง จึงไม่มี FK)
     */
    public function vehicleOption(): BelongsTo
    {
        return $this->belongsTo(ScheduleVehicleOption::class, 'vehicle_option_id');
    }
}
