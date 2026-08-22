<?php

namespace App\Jobs;

use App\Models\SosAlert;
use App\Services\SosSmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ส่ง SMS แจ้ง SOS ให้ผู้รับ "หนึ่งเบอร์"
 *
 * แตกรายเบอร์ด้วยเหตุผลเดียวกับ [DeliverSosAlert]: ผู้ให้บริการ SMS ตอบช้า
 * เป็นวินาที คนสุดท้ายในลิสต์ไม่ควรต้องรอคิวของคนก่อนหน้าทั้งแถว และเบอร์ที่
 * ส่งไม่ผ่านต้อง retry ตัวเดียว ไม่ใช่ลากทั้งก้อนไปส่งซ้ำ
 */
class DeliverSosSms implements ShouldQueue
{
    use Queueable;

    public int $tries = SosSmsService::MAX_ATTEMPTS;

    public int $backoff = 5;

    public function __construct(private int $sosAlertId, private string $msisdn) {}

    public function handle(SosSmsService $sms): void
    {
        if (! $sms->enabled()) {
            return;
        }

        $alert = SosAlert::with(['user', 'schedule.trip'])->find($this->sosAlertId);

        if (! $alert) {
            return;
        }

        $sms->sendTo($alert, $this->msisdn);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('DeliverSosSms failed permanently', [
            'sos_alert_id' => $this->sosAlertId,
            'recipient' => $this->msisdn,
            'error' => $exception->getMessage(),
        ]);
    }
}
