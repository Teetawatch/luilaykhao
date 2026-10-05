<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatRestStopBoarding extends Model
{
    protected $fillable = ['stop_id', 'passenger_id', 'marked_by_id'];

    public function stop(): BelongsTo
    {
        return $this->belongsTo(ChatRestStop::class, 'stop_id');
    }
}
