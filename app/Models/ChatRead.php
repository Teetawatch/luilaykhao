<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatRead extends Model
{
    protected $fillable = [
        'schedule_id', 'user_id', 'last_read_message_id',
    ];

    protected function casts(): array
    {
        return [
            'last_read_message_id' => 'integer',
        ];
    }
}
