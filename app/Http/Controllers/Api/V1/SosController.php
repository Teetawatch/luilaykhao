<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\BroadcastSosAlert;
use App\Jobs\BroadcastSosResolved;
use App\Models\SosAlert;
use App\Models\TripSchedule;
use App\Services\SosParticipantService;
use App\Support\Countries;
use App\Support\MediaDisk;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SosController extends Controller
{
    use ApiResponse;

    public function __construct(private SosParticipantService $participants) {}

    public function trigger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_id' => ['required', 'integer'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'message' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        $user = $request->user();

        $schedule = TripSchedule::with(['trip', 'vehicle'])->find($validated['schedule_id']);

        if (! $schedule || ! $this->participants->includes($schedule, (int) $user->id)) {
            return $this->error('ไม่พบการเดินทางนี้ในบัญชีของคุณ', 404);
        }

        if (! $this->isWithinTripWindow($schedule)) {
            return $this->error('ใช้ SOS ได้เฉพาะช่วงเวลาทริปเท่านั้น', 422);
        }

        // Treat a repeated trigger within a short window as the same alert so a
        // client retrying over a flaky connection doesn't create duplicates.
        $recentAlert = SosAlert::where('user_id', $user->id)
            ->where('schedule_id', $schedule->id)
            ->where('status', 'active')
            ->where('created_at', '>=', now()->subMinutes(2))
            ->latest()
            ->first();

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('sos/'.date('Y/m'), MediaDisk::name());
        }

        if ($recentAlert) {
            // A retry may carry the photo that an earlier attempt failed to upload —
            // attach it to the existing alert rather than creating a duplicate.
            if ($photoPath && ! $recentAlert->photo_path) {
                $recentAlert->update(['photo_path' => $photoPath]);
                BroadcastSosAlert::dispatchAfterResponse($recentAlert->id);
            }

            return $this->success($this->presentAlert($recentAlert->fresh(['user', 'schedule.trip'])), 'ส่งสัญญาณ SOS แล้ว');
        }

        $alert = SosAlert::create([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'message' => $validated['message'] ?? null,
            'photo_path' => $photoPath,
            'contact_phone' => $user->phone,
            'status' => 'active',
        ]);

        // Notify recipients after the response is sent so a slow FCM round-trip
        // never holds up the sender — critical on weak (3G) connections.
        BroadcastSosAlert::dispatchAfterResponse($alert->id);

        return $this->success($this->presentAlert($alert->fresh(['user', 'schedule.trip'])), 'ส่งสัญญาณ SOS แล้ว');
    }

    /**
     * เคสที่ยังเปิดอยู่ในทุกรอบที่ผู้ใช้เกี่ยวข้อง — แอปเรียกตอนเปิด/กลับเข้าแอป
     * เพื่อกู้สัญญาณที่พลาดไปตอนเครื่องดับหรือเน็ตหลุด
     *
     * รวมเคสของตัวเองด้วย (ต่างจากเดิม) เพื่อให้คนที่กดแล้วแอปถูกปิด กลับมาเห็น
     * ว่าเคสตัวเองยังเปิดอยู่และกดปิดได้เมื่อปลอดภัยแล้ว
     */
    public function active(Request $request): JsonResponse
    {
        $user = $request->user();

        $scheduleIds = $this->participants->scheduleIdsFor((int) $user->id);

        $alerts = SosAlert::with(['user', 'schedule.trip'])
            ->whereIn('schedule_id', $scheduleIds)
            ->where('status', 'active')
            // เฉพาะเหตุที่ยังสด — เคสเก่าที่ไม่มีใครกดปิดไม่ควรเด้งไซเรนใส่คนที่
            // เพิ่งเปิดแอปหลังเหตุการณ์จบไปนานแล้ว
            ->where('created_at', '>=', now()->subDay())
            ->orderByDesc('created_at')
            ->get();

        return $this->success(
            $alerts->map(fn (SosAlert $a) => $this->presentAlert($a, (int) $user->id))->values()
        );
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $alert = SosAlert::with('schedule.vehicle')->findOrFail($id);

        if (! $alert->schedule || ! $this->participants->includes($alert->schedule, (int) $user->id)) {
            return $this->error('คุณไม่มีสิทธิ์ปิดเคสนี้', 403);
        }

        if ($alert->status === 'active') {
            $alert->update([
                'status' => 'resolved',
                'resolved_by' => $user->id,
                'resolved_at' => now(),
            ]);

            // บอกทุกคนในรอบว่าเคสปิดแล้ว — ฝั่งแอปใช้สัญญาณนี้หยุดไซเรนที่ยัง
            // ดังอยู่บนเครื่องที่ไม่ได้เปิดหน้า SOS
            BroadcastSosResolved::dispatchAfterResponse($alert->id);
        }

        return $this->success($this->presentAlert($alert->fresh(['user', 'schedule.trip']), (int) $user->id), 'ปิดเคส SOS แล้ว');
    }

    /**
     * SOS เปิดตั้งแต่ 1 วันก่อนออกเดินทางจริง (นับ departs_at ถ้ารถออกคืนก่อน
     * วันทริป) จนถึง 1 วันหลังวันกลับ
     *
     * ที่เผื่อท้ายไว้หนึ่งวันเพราะรถกลับดีเลย์ข้ามเที่ยงคืนเป็นเรื่องปกติ และนั่น
     * คือช่วงที่คนบนรถต้องการ SOS มากที่สุด — เดิมระบบตัดตรงเที่ยงคืนของวันกลับพอดี
     */
    private function isWithinTripWindow(TripSchedule $schedule): bool
    {
        $today = now(TripSchedule::REVIEW_AVAILABLE_TIMEZONE)->toDateString();

        $departure = $schedule->effectiveDepartureDate();

        if (! $departure) {
            return false;
        }

        $start = $departure->copy()->subDay()->toDateString();
        $end = ($schedule->return_date ?? $schedule->departure_date)
            ->copy()
            ->addDay()
            ->toDateString();

        return $today >= $start && $today <= $end;
    }

    private function presentAlert(SosAlert $alert, ?int $viewerId = null): array
    {
        return [
            'id' => $alert->id,
            'schedule_id' => $alert->schedule_id,
            'user_name' => $alert->user?->name,
            'message' => $alert->message,
            'photo_url' => MediaDisk::url($alert->photo_path),
            'contact_phone' => $alert->contact_phone,
            'latitude' => $alert->latitude,
            'longitude' => $alert->longitude,
            'status' => $alert->status,
            'is_mine' => $viewerId !== null && (int) $alert->user_id === $viewerId,
            // เบอร์ฉุกเฉินของประเทศที่รอบนี้ไปอยู่ — SOS เรียกทีมงานของเราได้
            // แต่เรียกรถพยาบาลของประเทศนั้นแทนลูกค้าไม่ได้ หน้าจอที่เปิดอยู่ตอน
            // เกิดเหตุจึงต้องมีเบอร์นั้นติดมาด้วย ไม่ใช่ให้ไปหาในเบราว์เซอร์เอง
            'emergency_numbers' => Countries::emergency(
                $alert->schedule?->trip?->isInternational()
                    ? $alert->schedule->trip->country_code
                    : null,
            ),
            'created_at' => $alert->created_at?->toISOString(),
            'resolved_at' => $alert->resolved_at?->toISOString(),
        ];
    }
}
