<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedalKudos extends Model
{
    protected $table = 'medal_kudos';

    protected $fillable = ['medal_id', 'user_id'];

    public function medal(): BelongsTo
    {
        return $this->belongsTo(TripMedal::class, 'medal_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
