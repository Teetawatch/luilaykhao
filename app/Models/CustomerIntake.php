<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * ข้อมูลลูกค้าที่กรอกมาเองก่อนจะมีการจอง — หนึ่งแถวคือลูกค้าหนึ่งกลุ่ม
 *
 * แก้ปัญหาที่ลูกค้าทักมาทางไลน์/เฟส/ไอจี แล้วแอดมินต้องนั่งพิมพ์ข้อมูล 11 ช่อง
 * ต่อคนเองจากบทสนทนา ตอนนี้ส่งลิงก์ให้กรอกแทน แล้วค่อยดึงไปเปิดการจอง
 *
 * กลุ่มมีลิงก์ของตัวเอง (`token`) เพราะคนจองมักจอง 4-5 คนแล้วยังไม่รู้ข้อมูล
 * ของเพื่อน — คนแรกส่งลิงก์นี้ต่อในแชทกลุ่ม แล้วแต่ละคนเข้ามากรอกของตัวเอง
 * คนละเวลาได้ ข้อมูลที่กรอกไปแล้วค้างรออยู่จนกว่าจะครบ
 */
class CustomerIntake extends Model
{
    /** ยังไม่ถูกดึงไปจอง เก็บไว้เท่านี้วันนับจากความเคลื่อนไหวครั้งล่าสุด */
    public const RETENTION_DAYS = 45;

    /** ดึงไปเปิดการจองแล้ว ข้อมูลไปอยู่บนการจองจริง เหลือไว้กันเหนียวเท่านี้วัน */
    public const CONVERTED_RETENTION_DAYS = 7;

    /** ประเภทของกลุ่มนี้ — ใบจองหนึ่งใบเป็นได้ประเภทเดียว จึงเก็บไว้ที่กลุ่ม ไม่ใช่รายคน */
    public const TYPE_NORMAL = IntakeLink::TYPE_NORMAL;

    public const TYPE_JOIN = IntakeLink::TYPE_JOIN;

    protected $fillable = [
        'intake_link_id', 'trip_schedule_id', 'booking_type', 'contact_name', 'contact_phone',
        'contact_email', 'party_size', 'source', 'note', 'status',
        'booking_id', 'converted_at', 'last_activity_at', 'team_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'party_size' => 'integer',
            'converted_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'team_notified_at' => 'datetime',
        ];
    }

    public static function mintToken(): string
    {
        return Str::random(40);
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(IntakeLink::class, 'intake_link_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'trip_schedule_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function people(): HasMany
    {
        return $this->hasMany(CustomerIntakePerson::class)->orderByDesc('is_lead')->orderBy('id');
    }

    public function groupUrl(): string
    {
        return route('public.intake.group.show', $this->token);
    }

    /** ยังรอเพื่อนอีกกี่คน — ติดลบไม่ได้ เพราะมากันเกินที่แจ้งไว้ก็เกิดขึ้นจริง */
    public function missingCount(): int
    {
        return max(0, $this->party_size - $this->people()->count());
    }

    public function isComplete(): bool
    {
        return $this->missingCount() === 0;
    }

    public function touchActivity(): void
    {
        $this->forceFill(['last_activity_at' => now()])->save();
    }

    /**
     * ยังเปิดให้เพื่อนกรอกเพิ่มได้ไหม — ปิดทันทีที่ถูกดึงไปเปิดการจองแล้ว
     * เพราะข้อมูลที่กรอกเข้ามาหลังจากนั้นจะไม่มีใครเห็นและไม่ถูกนำไปใช้
     */
    public function isJoinTrip(): bool
    {
        return $this->booking_type === self::TYPE_JOIN;
    }

    /** 'จอยทริป' / 'จองปกติ' — คำเดียวกับที่หน้าแอดมินและหน้าจองใช้ */
    public function bookingTypeLabel(): string
    {
        return $this->isJoinTrip() ? 'จอยทริป' : 'จองปกติ';
    }

    public function acceptsSubmissions(): bool
    {
        return $this->status === 'new';
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'new');
    }

    /** แถวที่ถึงกำหนดลบอัตโนมัติแล้ว — เกณฑ์ต่างกันตามว่าถูกใช้งานไปหรือยัง */
    public function scopeDueForPurge(Builder $query): Builder
    {
        $openCutoff = now()->subDays(self::RETENTION_DAYS);

        // ห่อทั้งก้อนไว้ในวงเล็บเดียว — ไม่งั้นเงื่อนไขที่ chunkById ต่อท้าย (id > ?)
        // จะไปจับคู่กับ OR ตัวหลังตัวเดียว แล้วกวาดแถวผิดชุด
        return $query->where(function (Builder $outer) use ($openCutoff) {
            $outer->where(function (Builder $q) use ($openCutoff) {
                $q->where('status', 'new')->where(
                    // แถวเก่าก่อนมีคอลัมน์นี้ (หรือเขียนไม่ติด) ให้ถอยไปนับจากวันที่สร้าง
                    // ไม่ใช่ถือว่า "ไม่มีความเคลื่อนไหว" แล้วลบทิ้งตั้งแต่วันแรก
                    fn (Builder $inner) => $inner
                        ->where('last_activity_at', '<', $openCutoff)
                        ->orWhere(fn (Builder $fallback) => $fallback
                            ->whereNull('last_activity_at')
                            ->where('created_at', '<', $openCutoff))
                );
            })->orWhere(function (Builder $q) {
                $q->whereIn('status', ['booked', 'archived'])
                    ->where('updated_at', '<', now()->subDays(self::CONVERTED_RETENTION_DAYS));
            });
        });
    }
}
