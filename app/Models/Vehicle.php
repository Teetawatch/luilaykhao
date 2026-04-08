<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'type', 'capacity', 'seat_layout',
        'license_plate', 'color', 'driver_name', 'driver_phone', 'images',
        'driver_photo', 'interior_video',
    ];

    protected function casts(): array
    {
        return [
            'seat_layout' => 'array',
            'images' => 'array',
            'capacity' => 'integer',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TripSchedule::class);
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(VehicleMaintenance::class);
    }

    public function pickupPoints(): HasMany
    {
        return $this->hasMany(VehiclePickupPoint::class)->orderBy('region')->orderBy('sort_order');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(VehicleLocation::class);
    }

    public function latestLocation(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(VehicleLocation::class)->latestOfMany('recorded_at');
    }
}
