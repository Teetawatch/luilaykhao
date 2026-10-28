<?php

namespace App\Models;

use App\Services\BookingDocumentService;
use App\Services\PermitSignatureService;
use App\Support\PermitFormFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class BookingPassenger extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id', 'title', 'name', 'nickname', 'id_card', 'birth_date', 'phone', 'email', 'health_notes',
        'name_en', 'nationality', 'passport_no', 'passport_expires_at', 'travel_document',
        'emergency_contact', 'emergency_phone',
        'dive_cert_level', 'cert_number', 'weight',
        'blood_group', 'allergies', 'halal_food', 'pickup_point_id',
        'custom_pickup_label', 'custom_pickup_lat', 'custom_pickup_lng', 'custom_pickup_note',
        'self_fill_token', 'self_fill_expires_at', 'self_filled_at',
    ];

    /**
     * รหัสขึ้นรถและลิงก์ของเพื่อนเป็นของเจ้าตัว — ใบจองถูก serialize ตรง ๆ ในหลาย
     * endpoint (รวมฝั่งที่เพื่อนร่วมใบจองเปิดดูได้) จึงซ่อนไว้ก่อน แล้วค่อยส่งออก
     * เฉพาะคนที่มีสิทธิ์ผ่าน CheckInPassService
     */
    protected $hidden = ['qr_code', 'pass_token', 'permit_signature_path', 'permit_sign_meta', 'permit_extra'];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'birth_date' => 'date',
            'health_notes' => 'encrypted',
            'id_card' => 'encrypted',
            'passport_no' => 'encrypted',
            'passport_expires_at' => 'date',
            'allergies' => 'encrypted',
            'halal_food' => 'boolean',
            'self_fill_expires_at' => 'datetime',
            'self_filled_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'not_going_at' => 'datetime',
            'permit_signed_at' => 'datetime',
            'permit_sign_meta' => 'array',
            // ภูมิลำเนา/โทรศัพท์บ้านที่อุทยานบังคับ — ถามตอนเซ็นเท่านั้น ดู PermitFormFields::ASK_INPUTS
            'permit_extra' => 'encrypted:array',
            'custom_pickup_lat' => 'float',
            'custom_pickup_lng' => 'float',
        ];
    }

    /**
     * qr_code / pass_token ไม่อยู่ใน $fillable โดยตั้งใจ — ทุกทางที่สร้างผู้โดยสาร
     * (จองเอง, แอดมินคีย์, ส่งต่อที่นั่ง) ส่ง array จากคำขอเข้ามา ห้ามให้ไคลเอนต์
     * กำหนดรหัสขึ้นรถเองได้ ออกให้ที่นี่ที่เดียว
     */
    protected static function booted(): void
    {
        static::creating(function (BookingPassenger $passenger) {
            if (blank($passenger->qr_code)) {
                $passenger->qr_code = Booking::generateQrCode();
            }

            // ใบจองที่ "ทุกคนขึ้นรถแล้ว" (เช็คอินยกใบ) ยังต้องเป็นอย่างนั้นหลังมีชื่อเพิ่ม —
            // ไม่งั้น bookings.checked_in บอกว่าขึ้นรถแล้วแต่ไม่มีใครในรายชื่อขึ้นสักคน
            // ส่วนใบที่ขึ้นมาบางคน คนที่เพิ่มเข้ามาใหม่ยังไม่ได้ขึ้น
            if ($passenger->checked_in_at === null && $passenger->booking_id) {
                $booking = Booking::query()
                    ->whereKey($passenger->booking_id)
                    ->first(['id', 'checked_in', 'checked_in_at']);

                if ($booking?->checked_in && ! static::where('booking_id', $passenger->booking_id)
                    ->whereNull('checked_in_at')
                    ->exists()) {
                    $passenger->checked_in_at = $booking->checked_in_at ?? now();
                }
            }
        });

        // เลิกใช้ Border Pass (เปลี่ยนไปใช้พาสปอร์ต) = สำเนาบัตรประชาชนที่แนบไว้
        // หมดหน้าที่ทันที ไม่ต้องรอรอบลบหลังจบทริป
        static::updated(function (BookingPassenger $passenger) {
            if ($passenger->wasChanged('travel_document')
                && $passenger->getOriginal('travel_document') === Trip::DOC_BORDER_PASS
                && $passenger->travel_document !== Trip::DOC_BORDER_PASS) {
                app(BookingDocumentService::class)->purgeBorderPass($passenger);
            }

            // เซ็นใหม่หรือล้างลายเซ็น — ไฟล์เดิมไม่มีใครอ้างถึงแล้ว
            if ($passenger->wasChanged('permit_signature_path') && filled($passenger->getOriginal('permit_signature_path'))) {
                PermitSignatureService::deleteFile($passenger->getOriginal('permit_signature_path'));
            }
        });

        static::deleted(function (BookingPassenger $passenger) {
            PermitSignatureService::deleteFile($passenger->permit_signature_path);
        });
    }

    /**
     * เสร็จสำหรับแบบฟอร์มนี้ = เซ็นแล้ว และกรอกข้อมูลที่ฟอร์มถามตอนเซ็นครบ (คนที่เซ็นก่อน
     * แอดมินเพิ่มช่องที่อยู่ ยังต้องกลับมากรอกแล้วเซ็นยืนยันอีกครั้ง)
     */
    public function hasCompletedPermitForm(PermitForm $form): bool
    {
        if (! $this->hasSignedPermitForm($form->id)) {
            return false;
        }

        $extra = (array) ($this->permit_extra ?? []);

        foreach (PermitFormFields::askedInputs($form->fields ?? []) as $key) {
            if (trim((string) ($extra[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /** เซ็นแบบฟอร์มอุทยานฉบับนี้แล้วหรือยัง — เปลี่ยนแบบฟอร์มแล้วต้องเซ็นใหม่ */
    public function hasSignedPermitForm(?int $formId): bool
    {
        return $formId !== null
            && $this->permit_signed_at !== null
            && filled($this->permit_signature_path)
            && (int) $this->permit_signed_form_id === $formId;
    }

    /** หมุดจุดรับของคนนี้เอง (ไม่นับหมุดของใบจอง) */
    public function ownPickupPin(): ?array
    {
        if ($this->custom_pickup_lat === null || $this->custom_pickup_lng === null) {
            return null;
        }

        return Booking::pinPayload('passenger', $this->custom_pickup_label, $this->custom_pickup_lat, $this->custom_pickup_lng, $this->custom_pickup_note);
    }

    /**
     * หมุดที่คนนี้ไปยืนรอจริง: หมุดของตัวเองก่อน แล้วค่อยหมุดของใบจอง — null = ขึ้นที่
     * จุดรับตายตัว (หรือยังไม่ระบุ) ซึ่งตัดสินต่อที่ PickupArrivalService::passengerPointId
     */
    public function pickupPin(Booking $booking): ?array
    {
        return $this->ownPickupPin() ?? $booking->pickupPin();
    }

    /** ขึ้นรถแล้ว */
    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    /** แจ้งไว้ว่าไม่ไป (และยังไม่ได้โผล่มาขึ้นรถ) */
    public function isNotGoing(): bool
    {
        return $this->not_going_at !== null && $this->checked_in_at === null;
    }

    /** คนที่สตาฟยังต้องรอรับ — ยังไม่ขึ้นรถ และไม่ได้แจ้งว่าไม่ไป */
    public function isAwaitingBoarding(): bool
    {
        return $this->checked_in_at === null && $this->not_going_at === null;
    }

    /** ลิงก์ของเพื่อนคนนี้ — ออกครั้งแรกตอนมีคนขอ แล้วใช้ตัวเดิมตลอด */
    public function ensurePassToken(): string
    {
        if (blank($this->pass_token)) {
            $this->forceFill(['pass_token' => Str::random(40)])->save();
        }

        return $this->pass_token;
    }

    public function passUrl(): ?string
    {
        return $this->pass_token ? url('/f/'.$this->pass_token) : null;
    }

    /** ชื่อที่ใช้เรียกบนบัตร/รายชื่อ — ชื่อเล่นก่อน */
    public function displayName(): string
    {
        return trim((string) ($this->nickname ?: $this->name)) ?: 'ผู้เดินทาง';
    }

    /** สมาชิกแอปที่ผูกกับที่นั่งนี้ (คำเชิญที่ยังรอรับก็นับ — ที่นั่งนี้มีเจ้าของแล้ว) */
    public function member(): HasOne
    {
        return $this->hasOne(BookingMember::class, 'passenger_id')
            ->whereIn('status', [BookingMember::STATUS_ACTIVE, BookingMember::STATUS_PENDING]);
    }

    /** Age in whole years, computed live from birth_date; null when unknown. */
    public function getAgeAttribute(): ?int
    {
        return $this->birth_date?->age;
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function pickupPoint(): BelongsTo
    {
        return $this->belongsTo(SchedulePickupPoint::class, 'pickup_point_id');
    }

    /** เอกสารที่ผู้เดินทางคนนี้แนบมา */
    public function documents(): HasMany
    {
        return $this->hasMany(BookingDocument::class, 'booking_passenger_id');
    }
}
