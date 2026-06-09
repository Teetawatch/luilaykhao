<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BroadcastDispatch extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_type', 'dedupe_key',
    ];
}
