<?php

namespace App\Http\Controllers;

use App\Models\BookingPassenger;
use App\Models\PermitForm;
use App\Models\StaffPermitSignature;
use App\Services\PermitFormService;
use App\Services\PermitSignatureService;
use App\Services\StaffPermitService;
use App\Services\TripBriefService;
use App\Support\ThaiDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * เซ็นแบบฟอร์มอุทยานผ่านลิงก์ของเพื่อน /f/{token}/permit — ไม่ต้องมีแอป ไม่ต้อง
 * ล็อกอิน เปิดจากไลน์ได้เลย
 *
 * ลิงก์ของเพื่อนถูกส่งต่อในกลุ่มแชทได้ หน้านี้จึงปิดข้อมูลส่วนตัวไว้บางส่วน
 * (เลขบัตร เบอร์ โรคประจำตัว — เหลือแค่ท้ายให้เจ้าตัวจำได้) และไม่ให้โหลด PDF
 * ที่มีข้อมูลเต็ม คนจองกับเจ้าตัวดู PDF เต็มได้ในแอป/เว็บที่ต้องล็อกอิน
 *
 * ภาพหน้าแบบฟอร์มและภาพลายเซ็นเสิร์ฟผ่าน signed URL อายุสั้น เพื่อให้ <img> ใน
 * Blade/เว็บ/แอปเปิดได้โดยไม่ต้องแนบ token
 */
class PublicPermitFormController extends Controller
{
    public function __construct(
        private PermitSignatureService $signatures,
        private PermitFormService $forms,
        private TripBriefService $briefs,
        private StaffPermitService $staffPermits,
    ) {}

    public function show(string $token): Response
    {
        [$passenger, $form] = $this->resolve($token);

        if (! $passenger) {
            return response()->view('friend-permit', ['p' => null], 404);
        }

        $booking = $passenger->booking;
        $deadline = $this->signatures->deadline($booking, $form);

        return response()->view('friend-permit', ['p' => [
            'action' => route('public.friend-permit.sign', $token),
            'name' => $passenger->displayName(),
            'trip_title' => $booking->schedule?->trip?->title ?? 'ทริปของคุณ',
            'form_name' => $form->name,
            'open' => $this->signatures->isOpen($booking),
            'signed' => $passenger->hasCompletedPermitForm($form),
            'signed_label' => $passenger->hasCompletedPermitForm($form)
                ? ThaiDate::full($passenger->permit_signed_at->copy()->timezone('Asia/Bangkok'))
                    .' เวลา '.$passenger->permit_signed_at->copy()->timezone('Asia/Bangkok')->format('H:i').' น.'
                : null,
            'deadline_label' => $deadline ? ThaiDate::full($deadline) : null,
            'overdue' => $deadline !== null && $deadline->toDateString() < now('Asia/Bangkok')->toDateString(),
            // ลิงก์นี้ส่งต่อในกลุ่มได้ — ไม่เติมที่อยู่เดิมกลับมาให้ (ต้องกรอกใหม่ถ้าเซ็นซ้ำ)
            'ask' => $this->signatures->askSpec($form),
            ...$this->forms->preview($form, $passenger, mask: true),
        ]]);
    }

    public function sign(Request $request, string $token): RedirectResponse
    {
        [$passenger, $form] = $this->resolve($token);
        abort_if(! $passenger, 404);

        $back = redirect()->route('public.friend-permit.show', $token);

        $validated = $request->validate([
            'signature' => ['required', 'string', 'max:'.(int) (PermitSignatureService::MAX_BYTES * 1.4)],
            'accept' => ['accepted'],
            'extra' => ['sometimes', 'array'],
            'extra.*' => ['nullable', 'string', 'max:100'],
        ], [
            'signature.required' => 'ยังไม่ได้เซ็น วาดลายเซ็นในกรอบก่อนกดยืนยัน',
            'accept.accepted' => 'กรุณาติ๊กยอมรับข้อความในแบบฟอร์มก่อนเซ็น',
        ]);

        try {
            $this->signatures->sign($passenger, $form, $validated['signature'], [
                'channel' => PermitSignatureService::CHANNEL_FRIEND_LINK,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ], (array) ($validated['extra'] ?? []));
        } catch (\Exception $e) {
            return $back->withInput($request->except('signature'))->with('error', $e->getMessage());
        }

        return $back->with('notice', 'เซ็นแบบฟอร์มเรียบร้อยแล้ว ขอบคุณครับ ทีมงานจะนำไปลงทะเบียนกับอุทยานให้');
    }

    /**
     * แบบฟอร์มของสตาฟผ่านลิงก์ /staff-permit/{token} — หน้าเดียวกับของเพื่อน ข้อมูล
     * เติมจากโปรไฟล์ ลิงก์ใช้ได้ระหว่างที่ยังเป็นสตาฟของรอบและรอบยังไม่ผ่านไป
     */
    public function staffShow(string $token): Response
    {
        [$row, $form] = $this->resolveStaff($token);

        if (! $row) {
            return response()->view('friend-permit', ['p' => null], 404);
        }

        $schedule = $row->schedule;
        $preview = $this->staffPermits->preview($schedule, $row->user, $form, mask: true);
        $signedAt = $row->signed_at?->copy()->timezone('Asia/Bangkok');

        return response()->view('friend-permit', ['p' => [
            'action' => route('public.staff-permit.sign', $token),
            'name' => $preview['passenger']['name'],
            'trip_title' => ($schedule->trip?->title ?? 'ทริป').' · '.$schedule->departureLabelThai(),
            'form_name' => $form->name,
            'open' => $preview['open'],
            'signed' => $preview['signed'],
            'signed_label' => $preview['signed'] && $signedAt
                ? ThaiDate::full($signedAt).' เวลา '.$signedAt->format('H:i').' น.'
                : null,
            'deadline_label' => $preview['deadline_label'],
            'overdue' => $preview['overdue'],
            'intro' => 'อุทยานลงทะเบียนทีมงานทุกคนที่เข้าพื้นที่ด้วย แบบฟอร์มนี้เติมข้อมูลจากโปรไฟล์ของคุณในแอปแล้ว'
                .' (ข้อมูลส่วนตัวถูกปิดไว้บางส่วนในหน้านี้)',
            'missing_profile' => $preview['missing_profile'],
            'ask' => $preview['ask'],
            'pages' => $preview['pages'],
            'fields' => $preview['fields'],
        ]]);
    }

    public function staffSign(Request $request, string $token): RedirectResponse
    {
        [$row, $form] = $this->resolveStaff($token);
        abort_if(! $row, 404);

        $back = redirect()->route('public.staff-permit.show', $token);

        $validated = $request->validate([
            'signature' => ['required', 'string', 'max:'.(int) (PermitSignatureService::MAX_BYTES * 1.4)],
            'accept' => ['accepted'],
            'extra' => ['sometimes', 'array'],
            'extra.*' => ['nullable', 'string', 'max:100'],
        ], [
            'signature.required' => 'ยังไม่ได้เซ็น วาดลายเซ็นในกรอบก่อนกดยืนยัน',
            'accept.accepted' => 'กรุณาติ๊กยอมรับข้อความในแบบฟอร์มก่อนเซ็น',
        ]);

        try {
            $this->staffPermits->sign($row, $form, $validated['signature'], [
                'channel' => StaffPermitService::CHANNEL_STAFF_LINK,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ], (array) ($validated['extra'] ?? []));
        } catch (\Exception $e) {
            return $back->withInput($request->except('signature'))->with('error', $e->getMessage());
        }

        return $back->with('notice', 'เซ็นแบบฟอร์มเรียบร้อยแล้ว ขอบคุณครับ');
    }

    public function staffSignature(int $row): BaseResponse
    {
        $path = StaffPermitSignature::findOrFail($row)->signature_path;
        abort_if(blank($path) || ! Storage::disk(PermitSignatureService::DISK)->exists($path), 404);

        return Storage::disk(PermitSignatureService::DISK)->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    /** ภาพหน้าแบบฟอร์มเปล่า — ไม่มีข้อมูลส่วนตัว แต่ยังให้เปิดผ่าน signed URL เท่านั้น */
    public function page(int $form, int $page): BaseResponse
    {
        $entry = PermitForm::findOrFail($form)->pages[$page - 1] ?? abort(404);

        return Storage::disk(PermitFormService::DISK)->response($entry['path'], null, [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** ภาพลายเซ็น — ให้คนจอง/เจ้าตัวเห็นว่าเซ็นแบบไหนไปแล้ว (signed URL อายุสั้น) */
    public function signature(int $passenger): BaseResponse
    {
        $path = BookingPassenger::findOrFail($passenger)->permit_signature_path;
        abort_if(blank($path) || ! Storage::disk(PermitSignatureService::DISK)->exists($path), 404);

        return Storage::disk(PermitSignatureService::DISK)->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * ลิงก์ของสตาฟใช้ได้เมื่อยังเป็นสตาฟของรอบ และรอบยังไม่จบ (เซ็นแล้วยังเปิดดูได้ถึง
     * วันเดินทาง)
     *
     * @return array{0: ?StaffPermitSignature, 1: ?PermitForm}
     */
    private function resolveStaff(string $token): array
    {
        $row = StaffPermitSignature::with(['schedule.trip.permitForm', 'user'])->where('token', $token)->first();

        if (! $row?->schedule || ! $row->user
            || ! $this->staffPermits->isOpen($row->schedule)
            || ! $this->staffPermits->isActiveStaff($row->schedule, $row->user)) {
            return [null, null];
        }

        $form = $this->staffPermits->formFor($row->schedule);

        return $form ? [$row, $form] : [null, null];
    }

    /**
     * @return array{0: ?BookingPassenger, 1: ?PermitForm}
     */
    private function resolve(string $token): array
    {
        $passenger = BookingPassenger::with('booking.schedule.trip.permitForm')->where('pass_token', $token)->first();
        $booking = $passenger?->booking;

        if (! $booking || ! $this->briefs->isViewable($booking) || $passenger->not_going_at !== null) {
            return [null, null];
        }

        $form = $this->signatures->formNeedingSignature($booking);

        return $form ? [$passenger, $form] : [null, null];
    }
}
