<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripPostComment extends Model
{
    protected $fillable = ['post_id', 'user_id', 'body'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(TripPost::class, 'post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
