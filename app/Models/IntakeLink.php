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
    /** ลิงก์นี้พาไปสู่การจองปกติ (มีรถรับ) */
    public const TYPE_NORMAL = 'normal';

    /** ลิงก์นี้พาไปสู่การจอยทริป — ลูกค้าเดินทางไปเอง ไม่กินที่นั่งบนรถ */
    public const TYPE_JOIN = 'join';

    /** ยังไม่ล็อก ให้ลูกค้าเลือกเองในฟอร์ม (ใช้กับลิงก์กลางที่ยังไม่รู้รอบ) */
    public const TYPE_ASK = 'ask';

    public const TYPES = [self::TYPE_NORMAL, self::TYPE_JOIN, self::TYPE_ASK];

    protected $fillable = ['trip_schedule_id', 'booking_type', 'label', 'is_active', 'created_by'];

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

    /**
     * ลิงก์นี้ตัดสินประเภทให้แล้วหรือยัง — ถ้าล็อกไว้ ฟอร์มไม่ต้องถาม และค่าที่
     * ลูกค้าส่งมาก็เชื่อไม่ได้ (แก้ใน devtools ได้) จึงใช้ค่าของลิงก์เสมอ
     */
    public function locksBookingType(): bool
    {
        return $this->booking_type !== self::TYPE_ASK;
    }

    public function isJoinTrip(): bool
    {
        return $this->booking_type === self::TYPE_JOIN;
    }

    /**
     * ประเภทสุดท้ายของกลุ่มที่กรอกผ่านลิงก์นี้
     *
     * @param  mixed  $choice  ค่าที่ลูกค้าเลือกในฟอร์ม (ใช้เฉพาะลิงก์แบบ ask)
     */
    public function resolveBookingType(mixed $choice): string
    {
        if ($this->locksBookingType()) {
            return $this->booking_type;
        }

        return $choice === self::TYPE_JOIN ? self::TYPE_JOIN : self::TYPE_NORMAL;
    }

    public function markUsed(): void
    {
        $this->forceFill([
            'uses_count' => $this->uses_count + 1,
            'last_used_at' => now(),
        ])->save();
    }
}
