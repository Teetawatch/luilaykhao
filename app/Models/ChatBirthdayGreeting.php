<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** วันเกิดระหว่างทริปของผู้โดยสารหนึ่งคน — บอกทีมงานแล้ว / อวยพรในห้องแล้ว */
class ChatBirthdayGreeting extends Model
{
    protected $fillable = ['schedule_id', 'passenger_id', 'staff_notified_at', 'greeted_at', 'greeted_by_id'];

    protected function casts(): array
    {
        return [
            'staff_notified_at' => 'datetime',
            'greeted_at' => 'datetime',
        ];
    }
}
