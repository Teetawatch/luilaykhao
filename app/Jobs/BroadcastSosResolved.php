<?php

namespace App\Jobs;

use App\Models\SmartNotification;
use App\Models\SosAlert;
use App\Services\SosParticipantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * บอกทุกคนในรอบว่าเคส SOS ปิดแล้ว
 *
 * มีสองเหตุผล: (1) คนที่กดต้องรู้ว่ามีคนรับเรื่องแล้ว ไม่ใช่ตะโกนใส่ความเงียบ
 * และ (2) เครื่องที่ไซเรนยังดังอยู่เพราะเจ้าของยังไม่ได้เปิดหน้า SOS ต้องมี
 * สัญญาณให้หยุดเสียง — แอปฟัง type นี้แล้วสั่ง SosAlarmService.stop()
 */
class BroadcastSosResolved implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(private int $sosAlertId) {}

    public function handle(SosParticipantService $participants): void
    {
        $alert = SosAlert::with(['user', 'resolver', 'schedule.trip', 'schedule.vehicle'])->find($this->sosAlertId);

        if (! $alert || ! $alert->schedule) {
            return;
        }

        $senderName = $alert->user?->name ?? 'เพื่อนร่วมทริป';
        $resolverName = $alert->resolver?->name;

        $body = $resolverName
            ? "{$resolverName} รับเรื่องและปิดเคสของ{$senderName}แล้ว"
            : "เคสขอความช่วยเหลือของ{$senderName}ถูกปิดแล้ว";

        $data = [
            'sos_id' => (string) $alert->id,
            'schedule_id' => (string) $alert->schedule_id,
            'sos_user_name' => (string) $senderName,
        ];

        $participants->userIds($alert->schedule)->each(
            fn (int $id) => SmartNotification::send($id, 'sos_resolved', '✅ ปิดเคส SOS แล้ว', $body, $data)
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('BroadcastSosResolved failed permanently', [
            'sos_alert_id' => $this->sosAlertId,
            'error' => $exception->getMessage(),
        ]);
    }
}
