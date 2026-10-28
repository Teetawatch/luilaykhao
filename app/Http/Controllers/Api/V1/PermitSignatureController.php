<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\PermitForm;
use App\Services\PermitFormService;
use App\Services\PermitSignatureService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * เซ็นแบบฟอร์มอุทยานในแอปและเว็บ — คนจองเซ็นได้ทุกคนในใบจอง เพื่อนที่ผูกชื่อแล้ว
 * เซ็นได้เฉพาะตัวเอง ดู PermitSignatureService
 */
class PermitSignatureController extends Controller
{
    use ApiResponse;

    public function __construct(
        private PermitSignatureService $signatures,
        private PermitFormService $forms,
    ) {}

    /** สถานะของใบจอง — `data: null` = ทริปนี้ไม่ต้องเซ็นแบบฟอร์ม */
    public function show(Request $request, string $ref): JsonResponse
    {
        $booking = $this->booking($ref);

        if (! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('ไม่พบการจองนี้', 404);
        }

        return $this->success($this->signatures->summary($booking, $request->user()));
    }

    /** แบบฟอร์มของคนหนึ่ง สำหรับให้อ่านก่อนเซ็น */
    public function preview(Request $request, string $ref, int $passengerId): JsonResponse
    {
        [$booking, $passenger, $form] = $this->resolve($request, $ref, $passengerId);

        if (! $passenger) {
            return $this->error('ไม่พบผู้เดินทางคนนี้ในการจอง หรือคุณเซ็นแทนคนนี้ไม่ได้', 404);
        }

        $signed = $passenger->hasSignedPermitForm($form->id);

        return $this->success([
            'passenger' => ['id' => $passenger->id, 'name' => $passenger->displayName()],
            'form' => ['id' => $form->id, 'name' => $form->name],
            'open' => $this->signatures->isOpen($booking),
            'signed' => $signed,
            'signed_at' => $signed ? $passenger->permit_signed_at?->toISOString() : null,
            'signature_url' => $signed
                ? URL::temporarySignedRoute('public.permit-signature', now()->addMinutes(30), ['passenger' => $passenger->id])
                : null,
            ...$this->forms->preview($form, $passenger),
        ]);
    }

    /** PDF ของคนนี้ (มีลายเซ็นถ้าเซ็นแล้ว) */
    public function pdf(Request $request, string $ref, int $passengerId): Response|JsonResponse
    {
        [, $passenger, $form] = $this->resolve($request, $ref, $passengerId);

        if (! $passenger) {
            return $this->error('ไม่พบผู้เดินทางคนนี้ในการจอง หรือคุณเซ็นแทนคนนี้ไม่ได้', 404);
        }

        $filename = 'แบบฟอร์ม-'.$passenger->displayName().'.pdf';

        return response($this->forms->renderForPassenger($form, $passenger), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"permit-form.pdf\"; filename*=UTF-8''".rawurlencode($filename),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function sign(Request $request, string $ref, int $passengerId): JsonResponse
    {
        $validated = $request->validate([
            'signature' => ['required', 'string', 'max:'.(int) (PermitSignatureService::MAX_BYTES * 1.4)],
            'accept' => ['accepted'],
            'channel' => ['nullable', 'in:app,web'],
        ], [
            'accept.accepted' => 'กรุณาติ๊กยอมรับข้อความในแบบฟอร์มก่อนเซ็น',
        ]);

        [$booking, $passenger, $form] = $this->resolve($request, $ref, $passengerId);

        if (! $passenger) {
            return $this->error('ไม่พบผู้เดินทางคนนี้ในการจอง หรือคุณเซ็นแทนคนนี้ไม่ได้', 404);
        }

        try {
            $this->signatures->sign($passenger, $form, $validated['signature'], [
                'channel' => $validated['channel'] ?? PermitSignatureService::CHANNEL_APP,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'user_id' => $request->user()->id,
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $this->signatures->summary($booking->fresh('schedule.trip.permitForm'), $request->user()),
            'เซ็นแบบฟอร์มของ '.$passenger->displayName().' แล้ว',
        );
    }

    /**
     * @return array{0: Booking, 1: ?BookingPassenger, 2: ?PermitForm}
     */
    private function resolve(Request $request, string $ref, int $passengerId): array
    {
        $booking = $this->booking($ref);
        $user = $request->user();
        $form = $this->signatures->formNeedingSignature($booking);

        if (! $form || ! $booking->isAccessibleByUser($user->id)) {
            return [$booking, null, $form];
        }

        $passenger = $this->signatures->signablePassengers($booking, $user)->firstWhere('id', $passengerId);
        $passenger?->setRelation('booking', $booking);

        return [$booking, $passenger, $form];
    }

    private function booking(string $ref): Booking
    {
        return Booking::with('schedule.trip.permitForm')->where('booking_ref', $ref)->firstOrFail();
    }
}
