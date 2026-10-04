<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * หลักฐานการส่งอีเมลหนึ่งฉบับถึงผู้รับหนึ่งคน
 *
 * แถวถูกสร้างตอนเข้าคิว (queued) แล้ว LogSentEmail เติม sent_at, Message-ID และ
 * เนื้อหา HTML ที่ส่งออกไปจริงเมื่อ Brevo รับไป ถ้าคิวลองครบแล้วยังส่งไม่ได้
 * QueuedMail::failed() ทำเครื่องหมายว่า failed
 */
class EmailLog extends Model
{
    public const TYPE_UNDERFILLED_WARNING = 'trip_underfilled_warning';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'type', 'booking_id', 'schedule_id', 'user_id', 'booking_ref', 'recipient',
        'subject', 'status', 'message_id', 'html_body', 'error_message', 'meta',
        'sent_at', 'failed_at',
    ];

    protected $hidden = ['html_body'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }
}
