<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleMaintenance extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id', 'type', 'title', 'description',
        'scheduled_date', 'completed_date', 'status',
        'cost', 'performed_by', 'notes', 'next_km',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'completed_date' => 'date',
            'cost' => 'decimal:2',
            'next_km' => 'integer',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
