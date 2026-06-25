<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'value',
        'trip_ids',
        'max_uses',
        'used_count',
        'is_active',
        'is_flash_sale',
        'start_date',
        'end_date',
        'ends_at',
    ];

    protected $casts = [
        'trip_ids' => 'array',
        'is_active' => 'boolean',
        'is_flash_sale' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'ends_at' => 'datetime',
    ];

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
