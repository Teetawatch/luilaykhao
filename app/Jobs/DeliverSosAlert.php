<?php

namespace App\Jobs;

use App\Events\SosTriggered;
use App\Models\SmartNotification;
use App\Models\SosAlert;
use App\Support\MediaDisk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ส่งสัญญาณ SOS ให้ผู้รับ "หนึ่งคน"
 *
 * แยกออกมาเป็นงานต่อคนเพราะการยิง FCM เรียงกันในงานเดียวทำให้คนท้ายแถวของรอบ
 * 30 คนอาจรอหลายสิบวินาทีกว่าเครื่องจะดัง และถ้าคนกลางแถวพัง งานทั้งก้อนต้อง
 * retry ใหม่ทั้งหมด แยกแล้ว worker หลายตัวยิงขนานกันและ retry เฉพาะคนที่พลาด
 */
class DeliverSosAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(private int $sosAlertId, private int $recipientUserId) {}

    public function handle(): void
    {
        $alert = SosAlert::with(['user', 'schedule.trip'])->find($this->sosAlertId);

        if (! $alert || ! $alert->schedule || ! $alert->user) {
            return;
        }

        $sender = $alert->user;
        $tripTitle = $alert->schedule->trip?->title ?? 'ทริป';
        $photoUrl = MediaDisk::url($alert->photo_path);

        $title = '🆘 ขอความช่วยเหลือ SOS';
        $body = $sender->name.' ขอความช่วยเหลือในทริป '.$tripTitle;
        if ($alert->message) {
            $body .= ' — '.$alert->message;
        }

        SmartNotification::send($this->recipientUserId, 'sos_alert', $title, $body, [
            'sos_id' => (string) $alert->id,
            'schedule_id' => (string) $alert->schedule_id,
            'sos_user_name' => (string) $sender->name,
            'contact_phone' => (string) ($alert->contact_phone ?? ''),
            'latitude' => $alert->latitude !== null ? (string) $alert->latitude : '',
            'longitude' => $alert->longitude !== null ? (string) $alert->longitude : '',
            'sos_message' => (string) ($alert->message ?? ''),
            'photo_url' => (string) ($photoUrl ?? ''),
        ]);

        broadcast(new SosTriggered(
            recipientUserId: $this->recipientUserId,
            sosId: $alert->id,
            scheduleId: $alert->schedule_id,
            userName: $sender->name,
            message: $alert->message,
            contactPhone: $alert->contact_phone,
            latitude: $alert->latitude,
            longitude: $alert->longitude,
            photoUrl: $photoUrl,
        ));
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('DeliverSosAlert failed permanently', [
            'sos_alert_id' => $this->sosAlertId,
            'recipient_user_id' => $this->recipientUserId,
            'error' => $exception->getMessage(),
        ]);
    }
}
