<?php

namespace App\Services;

use App\Models\BookingPassenger;
use App\Models\PermitForm;
use App\Models\SmartNotification;
use App\Models\StaffPermitSignature;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\PermitFormFields;
use App\Support\ThaiDate;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * แบบฟอร์มอุทยานของสตาฟประจำรอบ
 *
 * อุทยานลงทะเบียนทุกคนที่เข้าพื้นที่ ทีมงานจึงต้องยื่นฟอร์มเหมือนลูกทริป ต่างกันที่
 * ข้อมูลมาจากโปรไฟล์ของสตาฟ (ไม่มีใบจอง) — ช่องไหนในโปรไฟล์ว่าง แอปบอกให้ไปกรอก
 * ก่อนเซ็น ที่อยู่ตามทะเบียนบ้านถามตอนเซ็นเหมือนลูกทริป เก็บแยกต่อรอบใน
 * `staff_permit_signatures` และลบหลังจบทริป (PurgePermitSignaturesJob)
 *
 * เซ็นได้เฉพาะตัวเอง จากแอป (เมนูงานสตาฟ) หรือลิงก์ /staff-permit/{token} ที่
 * แอดมินก๊อปส่งให้ — ไม่มีใครเซ็นแทนสตาฟได้
 */
class StaffPermitService
{
    public const CHANNEL_STAFF_LINK = 'staff_link';

    public function __construct(
        private PermitFormService $forms,
        private PermitSignatureService $signatures,
    ) {}

    /** ทริปของรอบนี้ให้เซ็นแบบฟอร์มไหม */
    public function formFor(TripSchedule $schedule): ?PermitForm
    {
        $schedule->loadMissing('trip.permitForm');
        $form = $schedule->trip?->permitForm;

        return $form?->requiresSignature() ? $form : null;
    }

    public function deadline(TripSchedule $schedule, PermitForm $form): ?CarbonInterface
    {
        if (! $schedule->departure_date || ! $form->sign_due_days) {
            return null;
        }

        return Carbon::parse($schedule->departure_date->toDateString(), 'Asia/Bangkok')->subDays($form->sign_due_days);
    }

    /** เซ็นได้จนถึงวันเดินทาง (เลยกำหนดก็ยังเซ็นได้ ทีมงานอาจยังลงทะเบียนให้ทัน) */
    public function isOpen(TripSchedule $schedule): bool
    {
        return $schedule->status !== 'cancelled'
            && $schedule->departure_date !== null
            && $schedule->departure_date->toDateString() >= now('Asia/Bangkok')->toDateString();
    }

    /** สตาฟที่ยังรับผิดชอบรอบนี้อยู่ — คนที่ถูกปลดหลังจบทริปไม่ต้องเซ็นแล้ว */
    public function isActiveStaff(TripSchedule $schedule, User $user): bool
    {
        return $schedule->activeStaff()->where('users.id', $user->id)->exists();
    }

    public function rowFor(TripSchedule $schedule, User $user): StaffPermitSignature
    {
        return StaffPermitSignature::firstOrCreate(['schedule_id' => $schedule->id, 'user_id' => $user->id]);
    }

    public function completed(StaffPermitSignature $row, User $user, PermitForm $form): bool
    {
        return StaffPermitSignature::asPassenger($user, $row)->hasCompletedPermitForm($form);
    }

    /**
     * ข้อมูลในโปรไฟล์ที่ฟอร์มนี้ใช้แต่ยังว่าง — ป้ายภาษาไทย
     *
     * @return list<string>
     */
    public function missingProfile(PermitForm $form, TripSchedule $schedule, BookingPassenger $person): array
    {
        return PermitFormFields::missingProfile($form->fields ?? [], PermitFormFields::values($person, $schedule, 0));
    }

    /**
     * แบบฟอร์มของสตาฟคนนี้สำหรับอ่านก่อนเซ็น — รูปแบบเดียวกับของลูกทริป แอปใช้
     * หน้าจอเดียวกันได้
     *
     * @param  bool  $mask  หน้าที่เปิดด้วยลิงก์ — ปิดข้อมูลส่วนตัวบางส่วน
     */
    public function preview(TripSchedule $schedule, User $user, PermitForm $form, bool $mask = false): array
    {
        $row = $this->rowFor($schedule, $user);
        $person = StaffPermitSignature::asPassenger($user, $row);
        $signed = $person->hasCompletedPermitForm($form);
        $deadline = $this->deadline($schedule, $form);

        return [
            'passenger' => ['id' => null, 'name' => $person->displayName()],
            'form' => ['id' => $form->id, 'name' => $form->name],
            'open' => $this->isOpen($schedule),
            'signed' => $signed,
            'signed_at' => $signed ? $row->signed_at?->toISOString() : null,
            'deadline_label' => $deadline ? ThaiDate::full($deadline) : null,
            'overdue' => $deadline !== null && $deadline->toDateString() < now('Asia/Bangkok')->toDateString(),
            // ฟอร์มของสตาฟเติมจากโปรไฟล์ — ช่องที่ยังว่างต้องไปกรอกในโปรไฟล์ก่อน
            'missing_profile' => $this->missingProfile($form, $schedule, $person),
            'ask' => $this->signatures->askSpec($form),
            'extra' => (object) ($mask ? [] : ($row->extra ?? [])),
            'address_suggestions' => [],
            'signature_url' => $signed
                ? URL::temporarySignedRoute('public.staff-permit-signature', now()->addMinutes(30), ['row' => $row->id])
                : null,
            ...$this->forms->preview($form, $person, $mask, $schedule),
        ];
    }

    /**
     * @param  array{channel: string, ip?: string|null, user_agent?: string|null, user_id?: int|null}  $meta
     *
     * @throws \Exception ข้อความภาษาไทยสำหรับแสดงสตาฟ
     */
    public function sign(StaffPermitSignature $row, PermitForm $form, string $dataUrl, array $meta, array $extra = []): StaffPermitSignature
    {
        $row->loadMissing('schedule', 'user');

        if (! $this->isOpen($row->schedule)) {
            throw new \Exception('รอบนี้เซ็นแบบฟอร์มไม่ได้แล้ว');
        }

        if (! $this->isActiveStaff($row->schedule, $row->user)) {
            throw new \Exception('คุณไม่ได้เป็นทีมงานของรอบนี้แล้ว');
        }

        // ตรวจข้อมูลก่อนแตะลายเซ็น — กรอกไม่ครบต้องไม่เหลือลายเซ็นค้างอยู่ครึ่ง ๆ
        $extra = $this->signatures->validateExtra($form, $extra);
        $path = $this->signatures->storeImage($dataUrl, 'staff-'.$row->id);

        $row->forceFill([
            'signature_path' => $path,
            'signed_at' => now(),
            'signed_form_id' => $form->id,
            'sign_meta' => [
                'channel' => $meta['channel'],
                'ip' => $meta['ip'] ?? null,
                'user_agent' => Str::limit((string) ($meta['user_agent'] ?? ''), 255, ''),
                'user_id' => $meta['user_id'] ?? null,
            ],
            'extra' => $extra === [] ? null : $extra,
        ])->save();

        return $row;
    }

    /** ล้างลายเซ็น (แอดมิน: เซ็นผิด / ข้อมูลเปลี่ยน ต้องเซ็นใหม่) */
    public function clear(StaffPermitSignature $row): void
    {
        $row->forceFill([
            'signature_path' => null,
            'signed_at' => null,
            'signed_form_id' => null,
            'sign_meta' => null,
            'extra' => null,
        ])->save();
    }

    public function signUrl(StaffPermitSignature $row): string
    {
        return url('/staff-permit/'.$row->token);
    }

    /** ข้อความพร้อมส่งทางไลน์ ให้แอดมินก๊อปไปส่งสตาฟ */
    public function shareText(StaffPermitSignature $row, User $user, TripSchedule $schedule, PermitForm $form): string
    {
        $deadline = $this->deadline($schedule, $form);
        $name = trim((string) ($user->nickname ?: $user->name)) ?: 'ทีมงาน';

        return 'คุณ'.$name.' (ทีมงาน) กรุณาอ่านและเซ็นแบบฟอร์มอุทยาน ('.($schedule->trip?->title ?? 'ทริป').')'
            .($deadline ? ' ภายใน '.ThaiDate::full($deadline) : '')
            .' ในแอปเมนูงานสตาฟ หรือที่ลิงก์นี้ครับ ข้อมูลเติมจากโปรไฟล์ของคุณ '
            .$this->signUrl($row);
    }

    /** สถานะของสตาฟคนนี้บนการ์ดงานในแอป — null = รอบนี้ไม่ต้องเซ็น */
    public function statusFor(TripSchedule $schedule, User $user): ?array
    {
        $form = $this->formFor($schedule);

        if (! $form) {
            return null;
        }

        $row = StaffPermitSignature::where('schedule_id', $schedule->id)->where('user_id', $user->id)->first();
        $deadline = $this->deadline($schedule, $form);

        return [
            'form_name' => $form->name,
            'signed' => StaffPermitSignature::asPassenger($user, $row)->hasCompletedPermitForm($form),
            'open' => $this->isOpen($schedule),
            'deadline_label' => $deadline ? ThaiDate::full($deadline) : null,
            'overdue' => $deadline !== null && $deadline->toDateString() < now('Asia/Bangkok')->toDateString(),
        ];
    }

    /**
     * แถวของสตาฟทุกคนในหน้าเอกสารรอบ (แอดมิน) — มีเมื่อทริปผูกแบบฟอร์มอุทยาน
     *
     * @return list<array<string, mixed>>
     */
    public function adminRows(TripSchedule $schedule, PermitForm $form): array
    {
        $needsSignature = $form->requiresSignature();

        return $this->forms->staffFor($schedule)->map(function (array $member) use ($schedule, $form, $needsSignature) {
            /** @var User $user */
            $user = $member['user'];
            $person = $member['passenger'];
            $signed = $needsSignature ? $person->hasCompletedPermitForm($form) : null;
            $row = $signed === false ? ($member['row'] ?? $this->rowFor($schedule, $user)) : $member['row'];

            return [
                'user_id' => $user->id,
                'title' => $user->title,
                'name' => $user->name,
                'nickname' => $user->nickname,
                'released' => $user->pivot?->released_at !== null,
                'permit_signed' => $signed,
                'permit_signed_at' => $signed ? $row?->signed_at?->toISOString() : null,
                'sign_url' => $signed === false ? $this->signUrl($row) : null,
                'share_text' => $signed === false ? $this->shareText($row, $user, $schedule, $form) : null,
                'reminded_today' => $row !== null && $this->remindedToday($row),
                'missing_profile' => $this->missingProfile($form, $schedule, $person),
            ];
        })->values()->all();
    }

    /**
     * เตือนสตาฟที่ยังไม่เซ็น (แจ้งเตือนในแอป)
     *
     * @param  bool  $manual  แอดมินกดเตือนเอง — ไม่นับเป็นโควตาเตือนอัตโนมัติ
     */
    public function remind(StaffPermitSignature $row, PermitForm $form, bool $manual = false): bool
    {
        $row->loadMissing('schedule.trip', 'user');

        if (! $row->user || $this->completed($row, $row->user, $form)) {
            return false;
        }

        // บันทึกก่อนส่ง — งานถูกลองซ้ำได้ อย่าให้คนเดียวกันได้ข้อความสองรอบในวันเดียว
        $row->forceFill([
            'reminded_at' => now(),
            'reminder_count' => $row->reminder_count + ($manual ? 0 : 1),
        ])->saveQuietly();

        $schedule = $row->schedule;
        $deadline = $this->deadline($schedule, $form);
        $missing = $this->missingProfile($form, $schedule, StaffPermitSignature::asPassenger($row->user, $row));

        SmartNotification::send(
            $row->user_id,
            'staff_permit_sign',
            'เซ็นแบบฟอร์มอุทยานของคุณ',
            ($schedule->trip?->title ?? 'รอบที่คุณดูแล').' · '.$schedule->departureLabelThai()
                .' — อุทยานลงทะเบียนทีมงานด้วย กรุณาเซ็น'.($deadline ? 'ภายใน '.ThaiDate::full($deadline) : 'ก่อนวันเดินทาง')
                .($missing !== [] ? ' (โปรไฟล์ยังขาด '.implode(', ', $missing).')' : ''),
            [
                'route' => 'staff_permit_sign',
                'schedule_id' => (string) $schedule->id,
                'trip_title' => (string) $schedule->trip?->title,
            ],
        );

        return true;
    }

    public function remindedToday(StaffPermitSignature $row): bool
    {
        return $row->reminded_at !== null
            && $row->reminded_at->copy()->timezone('Asia/Bangkok')->isSameDay(now('Asia/Bangkok'));
    }
}
