<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\PermitForm;
use App\Models\SmartNotification;
use App\Services\PermitSignatureService;
use App\Support\ThaiDate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * เตือนเซ็นแบบฟอร์มอุทยานก่อนถึงวันที่ทีมงานต้องลงทะเบียน
 *
 * แต่ละอุทยานปิดรับไม่เท่ากัน (`permit_forms.sign_due_days` วันก่อนเดินทาง) เตือน
 * วันละครั้งในช่วง {@see WINDOW_DAYS} วันก่อนถึงกำหนดจนถึงวันกำหนด ไม่เกิน
 * {@see MAX_REMINDERS} ครั้งต่อใบจอง — แบบฟอร์มที่ไม่ได้ตั้งจำนวนวันไว้ไม่เตือน
 *
 * - คนจอง: "ยังเซ็นไม่ครบ x/y คน" — คนที่ได้ทาง LINE (ไม่มีแอป) ลิงก์ไปใบเดินทาง
 *   #permit ซึ่งมีลิงก์เซ็นของแต่ละคน
 * - เพื่อนที่ผูกชื่อในแอปและยังไม่เซ็น: เตือนเจ้าตัวด้วย
 *
 * ก่อนเตือนตามกำหนด ไล่แจ้งครั้งแรกให้ใบจองที่ยังไม่เคยได้ (ปกติแจ้งตอนยืนยันการ
 * จองไปแล้ว — ที่ค้างคือรอบที่เพิ่งครบขั้นต่ำ/ทีมงานเพิ่งยืนยันออกเดินทาง หรือทริปที่
 * เพิ่งผูกแบบฟอร์มทีหลัง)
 */
class RemindPermitSignaturesJob implements ShouldQueue
{
    use Queueable;

    public const WINDOW_DAYS = 2;

    public const MAX_REMINDERS = 3;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(PermitSignatureService $signatures): void
    {
        $today = now('Asia/Bangkok')->startOfDay();
        $reminded = 0;
        $requested = $this->sendPendingFirstRequests($signatures, $today->toDateString());

        PermitForm::query()->whereNotNull('sign_due_days')->get()
            ->filter(fn (PermitForm $form) => $form->requiresSignature())
            ->each(function (PermitForm $form) use ($signatures, $today, &$reminded) {
                // กำหนดเซ็น = วันเดินทาง − sign_due_days → เตือนเมื่อวันนี้อยู่ใน
                // [กำหนด − WINDOW_DAYS, กำหนด] คือวันเดินทางอยู่ในช่วงข้างล่าง
                $from = $today->copy()->addDays($form->sign_due_days)->toDateString();
                $to = $today->copy()->addDays($form->sign_due_days + self::WINDOW_DAYS)->toDateString();

                Booking::query()
                    ->where('status', 'confirmed')
                    ->whereNotNull('user_id')
                    ->where('permit_reminder_count', '<', self::MAX_REMINDERS)
                    ->where(fn ($q) => $q->whereNull('permit_reminded_at')->orWhere('permit_reminded_at', '<', $today->copy()->utc()))
                    ->where(fn ($q) => $q->whereNull('force_majeure_at')->orWhereNotNull('force_majeure_resolved_at'))
                    ->whereHas('schedule', fn ($q) => $q
                        ->whereDate('departure_date', '>=', $from)
                        ->whereDate('departure_date', '<=', $to)
                        ->where('status', '!=', 'cancelled')
                        // รอบที่ยังไม่ครบขั้นต่ำ — ให้ลูกค้าเซ็นตอนนี้ = บอกว่ารอบออกแน่แล้ว
                        ->travelMessagesAllowed()
                        ->whereHas('trip', fn ($t) => $t->where('permit_form_id', $form->id)))
                    ->with(['schedule.trip', 'passengers'])
                    ->orderBy('id')
                    ->chunkById(200, function ($bookings) use ($form, $signatures, &$reminded) {
                        foreach ($bookings as $booking) {
                            if ($this->remind($booking, $form, $signatures)) {
                                $reminded++;
                            }
                        }
                    });
            });

        if ($reminded > 0 || $requested > 0) {
            Log::info('RemindPermitSignaturesJob: requested '.$requested.', reminded '.$reminded.' bookings');
        }
    }

    private function sendPendingFirstRequests(PermitSignatureService $signatures, string $today): int
    {
        $formIds = PermitForm::query()->get()
            ->filter(fn (PermitForm $form) => $form->requiresSignature())
            ->modelKeys();

        if ($formIds === []) {
            return 0;
        }

        $sent = 0;

        Booking::query()
            ->where('status', 'confirmed')
            ->whereNotNull('user_id')
            ->whereNull('permit_requested_at')
            ->whereHas('schedule', fn ($q) => $q
                ->whereDate('departure_date', '>=', $today)
                ->where('status', '!=', 'cancelled')
                ->travelMessagesAllowed()
                ->whereHas('trip', fn ($t) => $t->whereIn('permit_form_id', $formIds)))
            ->orderBy('id')
            ->chunkById(200, function ($bookings) use ($signatures, &$sent) {
                foreach ($bookings as $booking) {
                    if ($signatures->sendFirstRequest($booking)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    private function remind(Booking $booking, PermitForm $form, PermitSignatureService $signatures): bool
    {
        $going = $booking->passengers->whereNull('not_going_at');
        $unsigned = $going->reject(fn ($p) => $p->hasSignedPermitForm($form->id));

        if ($unsigned->isEmpty()) {
            return false;
        }

        // บันทึกก่อนส่ง — งานถูกลองซ้ำได้ อย่าให้คนเดียวกันได้ข้อความสองรอบในวันเดียว
        $booking->ensureBriefToken();
        $booking->forceFill([
            'permit_reminded_at' => now(),
            'permit_reminder_count' => $booking->permit_reminder_count + 1,
        ])->saveQuietly();

        $trip = $booking->schedule?->trip?->title ?? 'ทริปของคุณ';
        $deadline = $signatures->deadline($booking, $form);
        $due = $deadline ? 'ภายใน '.ThaiDate::full($deadline) : 'ก่อนวันเดินทาง';
        $data = [
            'booking_ref' => $booking->booking_ref,
            'route' => 'permit_form',
            'web_url' => $booking->briefUrl().'#permit',
        ];

        $total = $going->count();
        $signed = $total - $unsigned->count();

        SmartNotification::send(
            $booking->user_id,
            'permit_sign_reminder',
            $total > 1 ? "เซ็นแบบฟอร์มอุทยานแล้ว {$signed}/{$total} คน" : 'อย่าลืมเซ็นแบบฟอร์มอุทยาน',
            "{$trip} ต้องแนบแบบฟอร์มที่เซ็นแล้วตอนลงทะเบียนกับอุทยาน กรุณาเซ็น{$due} — "
                .($total > 1 ? 'ให้แต่ละคนเซ็นเอง หรือส่งลิงก์ให้เพื่อนได้' : 'ใช้เวลาไม่ถึงนาที'),
            $data,
        );

        // เพื่อนที่อยู่ในแอปและยังไม่เซ็นของตัวเอง
        BookingMember::where('booking_id', $booking->id)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->whereIn('passenger_id', $unsigned->pluck('id'))
            ->where('user_id', '!=', $booking->user_id)
            ->pluck('user_id')
            ->unique()
            ->each(fn ($userId) => SmartNotification::send(
                $userId,
                'permit_sign_reminder',
                'อย่าลืมเซ็นแบบฟอร์มอุทยาน',
                "{$trip} ต้องแนบแบบฟอร์มที่เซ็นแล้วตอนลงทะเบียนกับอุทยาน กรุณาเซ็น{$due}",
                $data,
            ));

        return true;
    }
}
