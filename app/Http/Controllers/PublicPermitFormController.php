<?php

namespace App\Http\Controllers;

use App\Models\BookingPassenger;
use App\Models\PermitForm;
use App\Services\PermitFormService;
use App\Services\PermitSignatureService;
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
            'token' => $token,
            'name' => $passenger->displayName(),
            'trip_title' => $booking->schedule?->trip?->title ?? 'ทริปของคุณ',
            'form_name' => $form->name,
            'open' => $this->signatures->isOpen($booking),
            'signed' => $passenger->hasSignedPermitForm($form->id),
            'signed_label' => $passenger->hasSignedPermitForm($form->id)
                ? ThaiDate::full($passenger->permit_signed_at->copy()->timezone('Asia/Bangkok'))
                    .' เวลา '.$passenger->permit_signed_at->copy()->timezone('Asia/Bangkok')->format('H:i').' น.'
                : null,
            'deadline_label' => $deadline ? ThaiDate::full($deadline) : null,
            'overdue' => $deadline !== null && $deadline->toDateString() < now('Asia/Bangkok')->toDateString(),
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
        ], [
            'signature.required' => 'ยังไม่ได้เซ็น วาดลายเซ็นในกรอบก่อนกดยืนยัน',
            'accept.accepted' => 'กรุณาติ๊กยอมรับข้อความในแบบฟอร์มก่อนเซ็น',
        ]);

        try {
            $this->signatures->sign($passenger, $form, $validated['signature'], [
                'channel' => PermitSignatureService::CHANNEL_FRIEND_LINK,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Exception $e) {
            return $back->with('error', $e->getMessage());
        }

        return $back->with('notice', 'เซ็นแบบฟอร์มเรียบร้อยแล้ว ขอบคุณครับ ทีมงานจะนำไปลงทะเบียนกับอุทยานให้');
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
