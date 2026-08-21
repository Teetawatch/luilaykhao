<?php

namespace App\Models;

use App\Support\MediaDisk;
use App\Support\TripDocumentRequirements;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ไฟล์เอกสารหนึ่งใบที่ลูกค้าแนบมาให้ตามที่ทริปขอ
 *
 * @see TripDocumentRequirements สำหรับฝั่ง "ข้อกำหนด" ที่แอดมินตั้ง
 */
class BookingDocument extends Model
{
    protected $fillable = [
        'booking_id', 'booking_passenger_id', 'requirement_key',
        'label', 'note', 'file_path', 'original_name', 'mime_type', 'size',
        'uploaded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(BookingPassenger::class, 'booking_passenger_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /** ลิงก์เปิดไฟล์ อายุสั้น — ไม่มี URL ถาวรสำหรับเอกสารระบุตัวบุคคล */
    public function url(): ?string
    {
        return MediaDisk::privateUrl($this->file_path);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }
}
