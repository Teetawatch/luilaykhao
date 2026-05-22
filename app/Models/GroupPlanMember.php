<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupPlanMember extends Model
{
    protected $fillable = [
        'group_plan_id', 'user_id', 'seat_id',
        'passenger_title', 'passenger_name', 'passenger_phone', 'passenger_email',
        'allergies', 'health_notes', 'is_host', 'status',
    ];

    protected function casts(): array
    {
        return [
            'is_host' => 'boolean',
            'allergies' => 'encrypted',
            'health_notes' => 'encrypted',
        ];
    }

    public function groupPlan(): BelongsTo
    {
        return $this->belongsTo(GroupPlan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Passenger payload shaped for BookingService::createBooking(). */
    public function toPassengerData(): array
    {
        return array_filter([
            'title' => $this->passenger_title,
            'name' => $this->passenger_name,
            'phone' => $this->passenger_phone,
            'email' => $this->passenger_email,
            'allergies' => $this->allergies,
            'health_notes' => $this->health_notes,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
