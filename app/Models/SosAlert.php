<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class SosAlert extends Model
{
    protected $fillable = [
        'user_id',
        'schedule_id',
        'latitude',
        'longitude',
        'message',
        'photo_path',
        'contact_phone',
        'occurred_at',
        'client_token',
        'source',
        'admin_note',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    /** ส่งตรงจากแอปตอนมีสัญญาณ */
    public const SOURCE_APP = 'app';

    /** ค้างอยู่ในคิวบนเครื่องเพราะไม่มีสัญญาณ แล้วถูกส่งตามมาทีหลัง */
    public const SOURCE_OFFLINE_QUEUE = 'offline_queue';

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** เวลาที่ผู้ใช้กดจริง — เคสเก่าที่ยังไม่มีค่านี้ถือว่าเท่ากับเวลาที่ระบบรับ */
    public function happenedAt(): ?Carbon
    {
        return $this->occurred_at ?? $this->created_at;
    }

    /**
     * ช่องว่างระหว่าง "กด" กับ "ระบบได้รับ" เป็นนาที
     *
     * ตัวเลขนี้คือสิ่งที่บอกทีมค้นหาว่าพิกัดที่เห็นเก่าแค่ไหน — SOS ที่ค้างมา
     * 90 นาทีหมายถึงคนคนนั้นอาจเดินต่อไปแล้วหลายกิโลเมตร
     */
    public function delayMinutes(): int
    {
        $occurred = $this->occurred_at;

        if (! $occurred || ! $this->created_at) {
            return 0;
        }

        return max(0, (int) round($occurred->diffInMinutes($this->created_at, absolute: true)));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
