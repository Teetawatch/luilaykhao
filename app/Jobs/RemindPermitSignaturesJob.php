<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\PermitForm;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\PermitSignatureService;
use App\Services\StaffPermitService;
use Carbon\CarbonInterface;
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
 * - ห้องแชทของรอบ: สรุปว่าใครยังไม่เซ็น วันละครั้งในวันเดียวกับที่เตือน (ไม่จำกัด
 *   MAX_REMINDERS ต่อใบ — ห้องเห็นทั้งรอบ ไม่ปลุกใคร) ครบแล้วห้องได้ข้อความขอบคุณ
 *
 * ก่อนเตือนตามกำหนด ไล่แจ้งครั้งแรกให้ใบจองที่ยังไม่เคยได้ (ปกติแจ้งตอนยืนยันการ
 * จองไปแล้ว — ที่ค้างคือรอบที่เพิ่งครบขั้นต่ำ/ทีมงานเพิ่งยืนยันออกเดินทาง หรือทริปที่
 * เพิ่งผูกแบบฟอร์มทีหลัง)
 *
 * สตาฟประจำรอบที่ยังไม่เซ็นของตัวเองก็ได้เตือนในแอป — เริ่มช่วงเดียวกับลูกทริป แต่เตือน
 * ต่อจนถึงวันเดินทาง (สตาฟมักถูกจัดลงรอบช้า หลังพ้นกำหนดไปแล้ว) ไม่เกิน MAX_REMINDERS
 */
class RemindPermitSignaturesJob implements ShouldQueue
{
    use Queueable;

    public const WINDOW_DAYS = 2;

    public const MAX_REMINDERS = 3;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(PermitSignatureService $signatures, StaffPermitService $staffPermits): void
    {
        $today = now('Asia/Bangkok')->startOfDay();
        $reminded = 0;
        $rooms = 0;
        $requested = $this->sendPendingFirstRequests($signatures, $today->toDateString());
        $staffReminded = $this->remindStaff($staffPermits, $today);

        PermitForm::query()->whereNotNull('sign_due_days')->get()
            ->filter(fn (PermitForm $form) => $form->requiresSignature())
            ->each(function (PermitForm $form) use ($signatures, $today, &$reminded, &$rooms) {
                // กำหนดเซ็น = วันเดินทาง − sign_due_days → เตือนเมื่อวันนี้อยู่ใน
                // [กำหนด − WINDOW_DAYS, กำหนด] คือวันเดินทางอยู่ในช่วงข้างล่าง
                $from = $today->copy()->addDays($form->sign_due_days)->toDateString();
                $to = $today->copy()->addDays($form->sign_due_days + self::WINDOW_DAYS)->toDateString();

                TripSchedule::query()
                    ->whereDate('departure_date', '>=', $from)
                    ->whereDate('departure_date', '<=', $to)
                    ->where('status', '!=', 'cancelled')
                    ->travelMessagesAllowed()
                    ->whereHas('trip', fn ($t) => $t->where('permit_form_id', $form->id))
                    ->get()
                    ->each(function (TripSchedule $schedule) use ($form, $signatures, &$rooms) {
                        $rooms += $signatures->postRoundReminder($schedule, $form) ? 1 : 0;
                    });

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
                            if ($signatures->sendReminder($booking, $form)) {
                                $reminded++;
                            }
                        }
                    });
            });

        if ($reminded > 0 || $requested > 0 || $staffReminded > 0 || $rooms > 0) {
            Log::info('RemindPermitSignaturesJob: requested '.$requested.', reminded '.$reminded.' bookings, '.$staffReminded.' staff, '.$rooms.' chat rooms');
        }
    }

    private function remindStaff(StaffPermitService $staffPermits, CarbonInterface $today): int
    {
        $sent = 0;

        PermitForm::query()->whereNotNull('sign_due_days')->get()
            ->filter(fn (PermitForm $form) => $form->requiresSignature())
            ->each(function (PermitForm $form) use ($staffPermits, $today, &$sent) {
                TripSchedule::query()
                    ->whereDate('departure_date', '>=', $today->toDateString())
                    ->whereDate('departure_date', '<=', $today->copy()->addDays($form->sign_due_days + self::WINDOW_DAYS)->toDateString())
                    ->where('status', '!=', 'cancelled')
                    ->travelMessagesAllowed()
                    ->whereHas('trip', fn ($t) => $t->where('permit_form_id', $form->id))
                    ->with(['trip', 'activeStaff'])
                    ->get()
                    ->each(function (TripSchedule $schedule) use ($form, $staffPermits, $today, &$sent) {
                        $schedule->activeStaff->each(function (User $user) use ($schedule, $form, $staffPermits, $today, &$sent) {
                            $row = $staffPermits->rowFor($schedule, $user);

                            if ($row->reminder_count >= self::MAX_REMINDERS
                                || ($row->reminded_at !== null && $row->reminded_at->gte($today->copy()->utc()))) {
                                return;
                            }

                            $row->setRelation('schedule', $schedule)->setRelation('user', $user);

                            if ($staffPermits->remind($row, $form)) {
                                $sent++;
                            }
                        });
                    });
            });

        return $sent;
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
}
