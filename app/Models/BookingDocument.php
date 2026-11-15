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
        return str_starts_with($this->resolvedMime(), 'image/');
    }

    public function isPdf(): bool
    {
        return $this->resolvedMime() === 'application/pdf';
    }

    /**
     * ชนิดไฟล์ที่เชื่อได้ — แอป Flutter (MultipartFile.fromPath) ส่งทุกไฟล์มาเป็น
     * application/octet-stream ไฟล์ที่แนบจากแอปก่อนแก้จึงบันทึกชนิดไว้แบบนั้น ถ้าชนิดที่
     * บันทึกไม่บอกอะไร ดูจากนามสกุลของไฟล์บนดิสก์แทน (store() ตั้งนามสกุลจากเนื้อไฟล์จริง
     * ไม่ใช่จากชื่อที่ลูกค้าส่งมา)
     */
    public function resolvedMime(): string
    {
        $mime = strtolower((string) $this->mime_type);

        if (str_starts_with($mime, 'image/') || $mime === 'application/pdf') {
            return $mime;
        }

        return match (strtolower(pathinfo((string) $this->file_path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'heic', 'heif' => 'image/heic',
            'pdf' => 'application/pdf',
            default => $mime,
        };
    }
}
