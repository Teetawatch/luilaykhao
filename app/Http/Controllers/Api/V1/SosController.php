<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\BroadcastSosAlert;
use App\Jobs\BroadcastSosResolved;
use App\Models\SosAlert;
use App\Models\TripSchedule;
use App\Services\SosContactService;
use App\Services\SosParticipantService;
use App\Support\Countries;
use App\Support\MediaDisk;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SosController extends Controller
{
    use ApiResponse;

    /**
     * เก่าได้แค่ไหนถึงยังรับ — SOS ที่ค้างในเครื่องข้ามคืนแล้วเพิ่งส่งออกมาได้
     * ยังมีค่าในการสอบสวนย้อนหลัง แต่เกินสองวันถือว่าเป็นขยะจากคิวที่ไม่ถูกล้าง
     */
    private const MAX_BACKDATE_HOURS = 48;

    /**
     * ต่างจากเวลาปัจจุบันเกินเท่าไรถึงนับว่า "มาจากคิวออฟไลน์"
     *
     * เผื่อไว้สำหรับนาฬิกาเครื่องที่เดินคลาดกันนิดหน่อยและเวลาที่ใช้รอ GPS —
     * ไม่ใช่ทุกวินาทีที่ต่างกันแปลว่าเคยส่งไม่ผ่าน
     */
    private const OFFLINE_QUEUE_THRESHOLD_SECONDS = 90;

    public function __construct(
        private SosParticipantService $participants,
        private SosContactService $contacts,
    ) {}

    public function trigger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_id' => ['required', 'integer'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'message' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:5120'],
            // เวลาที่ "กดจริง" บนเครื่อง ต่างจากเวลาที่คำขอมาถึงเมื่อสัญญาณเพิ่งกลับมา
            'occurred_at' => ['nullable', 'date'],
            // กุญแจกันซ้ำที่แอปสร้างตอนกด และคงเดิมตลอดอายุของรายการในคิว
            'client_token' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $request->user();

        $schedule = TripSchedule::with(['trip', 'vehicle'])->find($validated['schedule_id']);

        if (! $schedule || ! $this->participants->includes($schedule, (int) $user->id)) {
            return $this->error('ไม่พบการเดินทางนี้ในบัญชีของคุณ', 404);
        }

        $clientToken = $validated['client_token'] ?? null;

        // รายการเดิมจากคิวที่ถูกส่งซ้ำ — ตอบด้วยเคสเดิม ไม่สร้างใหม่ กลไกกันซ้ำ
        // แบบ "ภายใน 2 นาที" ข้างล่างครอบไม่ถึง เพราะคิวอาจถูกส่งซ้ำข้ามชั่วโมง
        if ($clientToken) {
            $existing = SosAlert::where('client_token', $clientToken)->first();

            if ($existing) {
                return $this->success(
                    $this->presentAlert($existing->fresh(['user', 'schedule.trip']), (int) $user->id),
                    'ส่งสัญญาณ SOS แล้ว',
                );
            }
        }

        $occurredAt = $this->resolveOccurredAt($validated['occurred_at'] ?? null);

        // ตรวจช่วงเวลาทริปจาก "ตอนที่กด" ไม่ใช่ตอนที่คำขอมาถึง — ไม่งั้น SOS ที่
        // กดคืนสุดท้ายบนดอยแล้วส่งออกได้ตอนรถถึงกรุงเทพจะถูกปฏิเสธทิ้ง
        if (! $this->isWithinTripWindow($schedule, $occurredAt)) {
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

        $delayedSeconds = $occurredAt ? $occurredAt->diffInSeconds(now(), absolute: true) : 0;

        $alert = SosAlert::create([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'message' => $validated['message'] ?? null,
            'photo_path' => $photoPath,
            'contact_phone' => $user->phone,
            'occurred_at' => $occurredAt ?? now(),
            'client_token' => $clientToken,
            'source' => $delayedSeconds > self::OFFLINE_QUEUE_THRESHOLD_SECONDS
                ? SosAlert::SOURCE_OFFLINE_QUEUE
                : SosAlert::SOURCE_APP,
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
     * เวลาที่กดจริงตามนาฬิกาของเครื่อง — คืน null เมื่อไม่ได้ส่งมาหรือไม่น่าเชื่อถือ
     *
     * นาฬิกาเครื่องเป็นค่าที่ผู้ใช้ตั้งเองได้ จึงรับเฉพาะค่าที่อยู่ในอดีตไม่เกิน
     * [MAX_BACKDATE_HOURS] และไม่ใช่อนาคต ค่าที่หลุดกรอบไม่ทำให้ SOS ตกไป —
     * แค่ถอยไปใช้เวลาที่เซิร์ฟเวอร์รับ เพราะสัญญาณขอความช่วยเหลือสำคัญกว่า
     * ความถูกต้องของ timestamp
     */
    private function resolveOccurredAt(?string $raw): ?Carbon
    {
        if (! $raw) {
            return null;
        }

        try {
            $occurred = Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }

        if ($occurred->isFuture() || $occurred->lt(now()->subHours(self::MAX_BACKDATE_HOURS))) {
            return null;
        }

        return $occurred;
    }

    /**
     * SOS เปิดตั้งแต่ 1 วันก่อนออกเดินทางจริง (นับ departs_at ถ้ารถออกคืนก่อน
     * วันทริป) จนถึง 1 วันหลังวันกลับ
     *
     * ที่เผื่อท้ายไว้หนึ่งวันเพราะรถกลับดีเลย์ข้ามเที่ยงคืนเป็นเรื่องปกติ และนั่น
     * คือช่วงที่คนบนรถต้องการ SOS มากที่สุด — เดิมระบบตัดตรงเที่ยงคืนของวันกลับพอดี
     */
    private function isWithinTripWindow(TripSchedule $schedule, ?Carbon $at = null): bool
    {
        $today = ($at ?? now())
            ->copy()
            ->timezone(TripSchedule::REVIEW_AVAILABLE_TIMEZONE)
            ->toDateString();

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
            // เวลาที่กดจริง กับช่องว่างจนถึงเวลาที่ระบบได้รับ — สองค่านี้ต่างกัน
            // เมื่อเคสค้างอยู่ในเครื่องเพราะไม่มีสัญญาณ และคนที่ออกไปค้นหาต้องรู้ว่า
            // พิกัดที่กำลังดูอยู่เก่าไปแล้วกี่นาที
            'occurred_at' => $alert->happenedAt()?->toISOString(),
            'delay_minutes' => $alert->delayMinutes(),
            'source' => $alert->source ?? SosAlert::SOURCE_APP,
            'resolved_at' => $alert->resolved_at?->toISOString(),
        ];
    }

    /**
     * เบอร์ที่โทร/ส่ง SMS ได้เมื่อ SOS ในแอปส่งไม่ออก
     *
     * แอปดึงล่วงหน้าตั้งแต่ตอนยังมีสัญญาณแล้วเก็บลงเครื่อง (ดู TripDayPack) —
     * ปลายทางของข้อมูลชุดนี้คือหน้าจอที่เปิดตอนไม่มีเน็ต ถ้าต้องเรียกตอนนั้นถึง
     * จะได้ ก็ไม่มีประโยชน์อะไรเลย
     */
    public function emergencyContacts(Request $request, int $scheduleId): JsonResponse
    {
        $user = $request->user();

        $schedule = TripSchedule::with(['trip', 'vehicle.driver'])->find($scheduleId);

        if (! $schedule || ! $this->participants->includes($schedule, (int) $user->id)) {
            return $this->error('ไม่พบการเดินทางนี้ในบัญชีของคุณ', 404);
        }

        return $this->success([
            'contacts' => $this->contacts->forSchedule($schedule),
            'emergency_numbers' => $this->contacts->emergencyNumbers($schedule),
        ]);
    }
}
