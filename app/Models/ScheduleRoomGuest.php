<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleRoomGuest extends Model
{
    protected $fillable = ['room_id', 'passenger_id'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(ScheduleRoom::class, 'room_id');
    }
}
