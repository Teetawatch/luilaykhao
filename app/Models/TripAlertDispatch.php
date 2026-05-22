<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripAlertDispatch extends Model
{
    protected $fillable = [
        'trip_alert_id', 'schedule_id', 'type',
    ];

    public function tripAlert(): BelongsTo
    {
        return $this->belongsTo(TripAlert::class);
    }
}
