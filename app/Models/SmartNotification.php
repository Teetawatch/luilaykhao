<?php

namespace App\Models;

use App\Jobs\DeliverTranslatedNotificationJob;
use App\Jobs\SendLineMessageJob;
use App\Services\FcmService;
use App\Services\LineMessagingService;
use App\Services\NotificationTranslator;
use App\Support\AppLocale;
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

        // ลูกค้าที่ใช้ภาษาอังกฤษ: แปลในคิวก่อนแล้วค่อยส่ง (ดู NotificationTranslator)
        $translator = app(NotificationTranslator::class);
        if ($translator->needs($type, $title, $body) && $translator->localeFor($userId) === AppLocale::ENGLISH) {
            DeliverTranslatedNotificationJob::dispatch($notification->id)->afterCommit();

            return $notification;
        }

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

    /**
     * [send] for a whole broadcast slice: every row is written first, then the
     * pushes go out together through [FcmService::sendNotifications] rather than
     * one FCM call after another — see SendBroadcastNotificationChunkJob.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, self>
     */
    public static function sendMany(
        array $userIds,
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?int $dispatchId = null,
    ): array {
        // ประกาศถึงหลายคน: แปลครั้งเดียวให้ทุกคนที่ใช้ภาษาอังกฤษ (งานนี้วิ่งในคิวอยู่แล้ว)
        $translator = app(NotificationTranslator::class);
        $englishIds = $translator->needs($type, $title, $body) && $userIds !== []
            ? array_flip(User::whereIn('id', $userIds)->where('locale', AppLocale::ENGLISH)->pluck('id')->all())
            : [];
        $english = $englishIds !== [] ? $translator->translate($title, $body) : null;

        $notifications = array_map(fn (int $userId) => static::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => isset($englishIds[$userId]) ? $english['title'] : $title,
            'body' => isset($englishIds[$userId]) ? $english['body'] : $body,
            'data' => $data,
            'broadcast_dispatch_id' => $dispatchId,
        ]), $userIds);

        try {
            app(FcmService::class)->sendNotifications($notifications);
        } catch (\Throwable $e) {
            Log::warning('Unable to send push notifications', [
                'type' => $type,
                'count' => count($notifications),
                'message' => $e->getMessage(),
            ]);
        }

        if (app(LineMessagingService::class)->shouldNotify($type)) {
            foreach ($notifications as $notification) {
                SendLineMessageJob::dispatch($notification->id);
            }
        }

        return $notifications;
    }
}
