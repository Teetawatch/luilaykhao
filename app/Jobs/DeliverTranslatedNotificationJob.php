<?php

namespace App\Jobs;

use App\Models\SmartNotification;
use App\Services\FcmService;
use App\Services\LineMessagingService;
use App\Services\NotificationTranslator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * ส่งแจ้งเตือนถึงลูกค้าภาษาอังกฤษ — แปลก่อน (NotificationTranslator) แล้วค่อยส่ง
 * push + LINE แถวในหน้าแจ้งเตือนถูกแก้เป็นภาษาอังกฤษด้วย จะได้ตรงกับที่เห็นบนเครื่อง
 *
 * ลองครั้งเดียวเหมือนการส่งตรงจาก SmartNotification::send — ส่งซ้ำแปลว่าลูกค้า
 * ได้ push สองรอบ
 */
class DeliverTranslatedNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $notificationId) {}

    public function handle(NotificationTranslator $translator, FcmService $fcm, LineMessagingService $line): void
    {
        $notification = SmartNotification::find($this->notificationId);
        if (! $notification) {
            return;
        }

        $english = $translator->translate((string) $notification->title, (string) $notification->body);
        if ($english['title'] !== $notification->title || $english['body'] !== $notification->body) {
            $notification->forceFill($english)->save();
        }

        try {
            $fcm->sendNotification($notification);
        } catch (\Throwable $e) {
            Log::warning('Unable to send push notification', [
                'notification_id' => $notification->id,
                'message' => $e->getMessage(),
            ]);
        }

        if ($line->shouldNotify($notification->type)) {
            SendLineMessageJob::dispatch($notification->id);
        }
    }
}
