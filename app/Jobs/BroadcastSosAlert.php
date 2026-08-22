<?php

namespace App\Jobs;

use App\Mail\AdminSosAlertMail;
use App\Models\SosAlert;
use App\Models\User;
use App\Services\SosContactService;
use App\Services\SosParticipantService;
use App\Services\SosSmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * กระจายสัญญาณ SOS ให้ทุกคนที่ควรรู้
 *
 * งานนี้ทำหน้าที่ "หารายชื่อ" อย่างเดียวแล้วแตกเป็น [DeliverSosAlert] รายคน
 * เพื่อให้ worker หลายตัวยิงขนานกัน — เวลาถึงเครื่องของคนท้ายแถวสำคัญพอ ๆ กับ
 * คนแรกในสถานการณ์ฉุกเฉิน
 */
class BroadcastSosAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(private int $sosAlertId) {}

    public function handle(
        SosParticipantService $participants,
        SosContactService $contacts,
        SosSmsService $sms,
    ): void {
        $alert = SosAlert::with(['user', 'schedule.trip', 'schedule.vehicle'])->find($this->sosAlertId);

        if (! $alert || ! $alert->schedule || ! $alert->user) {
            return;
        }

        $recipientIds = $participants->userIds($alert->schedule)
            ->reject(fn (int $id) => $id === (int) $alert->user_id);

        // ทีมงานออฟฟิศต้องรู้ทุกเคส แม้จะไม่ได้อยู่ในรอบนั้น — เดิมเห็นได้เฉพาะ
        // ตอนเปิดหน้าศูนย์เฝ้าระวังไว้ (poll ทุก 30 วิ) ซึ่งกลางดึกไม่มีใครเปิด
        $opsIds = User::role(['admin', 'operator'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $recipientIds->merge($opsIds)
            ->unique()
            ->reject(fn (int $id) => $id === (int) $alert->user_id)
            ->each(fn (int $id) => DeliverSosAlert::dispatch($alert->id, $id));

        $this->smsResponders($alert, $contacts, $sms);
        $this->emailOps($alert);
    }

    /**
     * SMS ถึงสตาฟ คนขับ และทีมออฟฟิศ — ช่องทางเดียวที่ไม่ต้องใช้ data
     *
     * push กับ Reverb ไปถึงเฉพาะเครื่องที่ยังต่อเน็ตอยู่ ซึ่งบนดอยคือเครื่องที่
     * ไม่มี แปลว่าสตาฟที่ยืนอยู่ห่างผู้ประสบเหตุ 300 เมตรอาจเป็นคนสุดท้ายที่รู้
     * SMS วิ่งบนช่องสัญญาณเสียงจึงไปถึงตรงที่แอปไปไม่ถึง
     */
    private function smsResponders(
        SosAlert $alert,
        SosContactService $contacts,
        SosSmsService $sms,
    ): void {
        if (! $sms->enabled()) {
            return;
        }

        $contacts->notifiablePhones($alert)
            ->each(fn (string $phone) => DeliverSosSms::dispatch($alert->id, $phone));
    }

    /**
     * อีเมลถึงแอดมิน — ช่องทางที่ไปถึงคนเวรกลางดึกได้จริงแม้ไม่มีแอปในเครื่อง
     */
    private function emailOps(SosAlert $alert): void
    {
        $emails = User::role('admin')
            ->whereNotNull('email')
            ->pluck('email')
            ->filter()
            ->all();

        if (empty($emails)) {
            return;
        }

        try {
            Mail::to($emails)->send(new AdminSosAlertMail($alert));
        } catch (\Throwable $e) {
            Log::error('Unable to email ops about SOS', [
                'sos_alert_id' => $alert->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('BroadcastSosAlert failed permanently', [
            'sos_alert_id' => $this->sosAlertId,
            'error' => $exception->getMessage(),
        ]);
    }
}
