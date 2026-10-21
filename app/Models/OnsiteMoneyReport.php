<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ยอดหน้างานของรอบที่แช่ไว้ก่อนห้องแชทถูกล้าง — ดู OnsiteMoneyService::freeze
 */
class OnsiteMoneyReport extends Model
{
    protected $fillable = ['schedule_id', 'summary', 'frozen_at'];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'frozen_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }
}
