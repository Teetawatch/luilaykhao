<?php

namespace App\Models;

use App\Jobs\SendLineMessageJob;
use App\Services\FcmService;
use App\Services\LineMessagingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class SmartNotification extends Model
{
    protected $fillable = [
        'user_id', 'type', 'title', 'body', 'data', 'is_read', 'read_at',
        'broadcast_dispatch_id',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  int|null  $dispatchId  Blast this notification belongs to, when it
     *                                came from a broadcast — lets the admin page
     *                                count exactly how many recipients read it.
     */
    public static function send(
        int $userId,
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?int $dispatchId = null,
    ): self {
        $notification = static::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'broadcast_dispatch_id' => $dispatchId,
        ]);

        try {
            app(FcmService::class)->sendNotification($notification);
        } catch (\Throwable $e) {
            Log::warning('Unable to send push notification', [
                'notification_id' => $notification->id,
                'message' => $e->getMessage(),
            ]);
        }

        // ลูกค้าที่จองผ่าน LINE แล้วไม่เคยลงแอป ได้แต่ SMS หรือไม่ได้อะไรเลย ทั้งที่
        // เราถือ LINE userId ของเขาอยู่ — คัดเฉพาะเรื่องที่คุ้มค่าจะส่ง (ดู config/line.php)
        // แล้วค่อยเข้าคิว เพื่อไม่ให้ทุกการแจ้งเตือนในระบบสร้างงานเปล่า
        if (app(LineMessagingService::class)->shouldNotify($notification->type)) {
            SendLineMessageJob::dispatch($notification->id);
        }

        return $notification;
    }
}
