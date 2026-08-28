<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * ลิงก์เก็บข้อมูลลูกค้า — แปะไว้ในไบโอไอจีหรือ auto-reply ไลน์ได้ ใช้ซ้ำได้ไม่จำกัดคน
 *
 * ต่างจากลิงก์ `/p/{token}` เดิมตรงที่ลิงก์นั้นออกให้ทีละคนและต้องมีการจองอยู่ก่อน
 * อันนี้มาก่อนการจอง — คนที่ทักมาในแชทเปิดลิงก์เดียวกันนี้แล้วกรอกเอง
 *
 * ลิงก์ใช้ซ้ำได้แปลว่าตัวลิงก์ไม่ใช่ความลับอีกต่อไป หน้าที่มันเปิดจึงต้อง
 * "เขียนได้อย่างเดียว" — เปิดกี่ครั้งก็เจอฟอร์มเปล่า ไม่เคยแสดงข้อมูลของใคร
 */
class IntakeLink extends Model
{
    protected $fillable = ['trip_schedule_id', 'label', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'uses_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public static function mintToken(): string
    {
        return Str::random(40);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'trip_schedule_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function intakes(): HasMany
    {
        return $this->hasMany(CustomerIntake::class);
    }

    public function publicUrl(): string
    {
        return route('public.intake.show', $this->token);
    }

    public function markUsed(): void
    {
        $this->forceFill([
            'uses_count' => $this->uses_count + 1,
            'last_used_at' => now(),
        ])->save();
    }
}
