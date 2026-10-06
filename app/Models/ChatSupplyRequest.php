<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ขอยา/ของจำเป็นจากสตาฟ — ส่งถึงที่นั่ง (บอกชื่อ แต่ไม่กระจายเข้าห้องรวม)
 */
class ChatSupplyRequest extends Model
{
    /** ของที่ขอได้ — key ถาวร (อย่าเปลี่ยนชื่อ แถวเก่าอ้างอยู่) */
    public const ITEMS = [
        'motion_sickness' => ['label' => 'ยาแก้เมารถ', 'emoji' => '💊'],
        'sick_bag' => ['label' => 'ถุงอาเจียน', 'emoji' => '🛍️'],
        'painkiller' => ['label' => 'ยาแก้ปวด / ลดไข้', 'emoji' => '🩹'],
        'plaster' => ['label' => 'พลาสเตอร์', 'emoji' => '🩹'],
        'inhaler' => ['label' => 'ยาดม', 'emoji' => '🌿'],
        'tissue' => ['label' => 'ทิชชู่', 'emoji' => '🧻'],
        'water' => ['label' => 'น้ำดื่ม', 'emoji' => '💧'],
        'other' => ['label' => 'อื่น ๆ', 'emoji' => '📦'],
    ];

    /** คำขอที่ยังไม่ได้จัดการต่อคนพร้อมกันได้สูงสุด — กันกดรัว */
    public const MAX_OPEN_PER_USER = 5;

    protected $fillable = [
        'schedule_id', 'user_id', 'passenger_id', 'item', 'note', 'seat_label',
        'delivered_at', 'declined_at', 'decline_note', 'handled_by_id',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function status(): string
    {
        return match (true) {
            $this->delivered_at !== null => 'delivered',
            $this->declined_at !== null => 'declined',
            default => 'pending',
        };
    }

    public function label(): string
    {
        return self::ITEMS[$this->item]['label'] ?? $this->item;
    }
}
