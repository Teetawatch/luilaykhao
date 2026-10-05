<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * คำขอแวะห้องน้ำแบบไม่บอกชื่อ — ทีมงานเห็นแค่จำนวน ไม่เห็นว่าใคร
 */
class ChatStopRequest extends Model
{
    protected $fillable = ['schedule_id', 'user_id', 'urgent', 'acknowledged_at'];

    protected function casts(): array
    {
        return [
            'urgent' => 'boolean',
            'acknowledged_at' => 'datetime',
        ];
    }
}
