<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerIntake;
use App\Models\IntakeLink;
use App\Services\QrCodeService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ฝั่งแอดมินของ "ลิงก์เก็บข้อมูลลูกค้า" — ออกลิงก์ ดูของที่กรอกเข้ามา แล้วดึงไปจอง
 *
 * ข้อมูลอ่อนไหว (เลขบัตร/แพ้อาหาร/โรคประจำตัว) ถอดรหัสให้เฉพาะตอนเปิดดูรายกลุ่ม
 * ไม่ส่งไปกับหน้ารายการ เพราะหน้ารายการโหลดทีละ 20 กลุ่มและไม่มีใครต้องใช้
 */
class AdminIntakeController extends Controller
{
    use ApiResponse;

    // ── ลิงก์ ────────────────────────────────────────────────────────────

    public function links(): JsonResponse
    {
        $links = IntakeLink::with('schedule.trip')
            ->withCount('intakes')
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get()
            ->map(fn (IntakeLink $link) => $this->linkPayload($link));

        return $this->success($links);
    }

    public function storeLink(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trip_schedule_id' => ['nullable', 'exists:trip_schedules,id'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $link = new IntakeLink([
            'trip_schedule_id' => $data['trip_schedule_id'] ?? null,
            'label' => $data['label'] ?? null,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);
        $link->token = IntakeLink::mintToken();
        $link->save();

        return $this->success(
            $this->linkPayload($link->load('schedule.trip')->loadCount('intakes')),
            'สร้างลิงก์แล้ว ส่งให้ลูกค้ากรอกได้เลย',
            201,
        );
    }

    public function updateLink(Request $request, int $id): JsonResponse
    {
        $link = IntakeLink::findOrFail($id);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // ปิดลิงก์ทำให้ลิงก์ที่ส่งไปแล้วใช้ไม่ได้ทันที แต่ข้อมูลที่กรอกมาแล้วยังอยู่
        $link->fill($data)->save();

        return $this->success($this->linkPayload($link->load('schedule.trip')->loadCount('intakes')), 'บันทึกแล้ว');
    }

    /**
     * QR ของลิงก์ — ช่องทางที่คัดลอก URL ไม่ได้ (rich menu ไลน์, สตอรี่ไอจี,
     * ป้ายหน้าบูธ, นามบัตร) ต้องการรูป ไม่ใช่ข้อความ
     */
    public function linkQr(int $id, QrCodeService $qr): JsonResponse
    {
        $link = IntakeLink::findOrFail($id);
        $url = $link->publicUrl();

        // ตัด XML declaration หัวไฟล์ออก — ฝั่งหน้าเว็บฝัง SVG นี้ลงใน HTML ตรง ๆ
        // ตัว declaration จะถูก parser มองเป็น comment เปล่า ๆ ที่ไม่มีประโยชน์
        $svg = preg_replace('/^<\?xml[^>]*>\s*/', '', $qr->svg($url, 420));

        return $this->success([
            'url' => $url,
            'label' => $link->label,
            'svg' => $svg,
        ]);
    }

    public function destroyLink(int $id): JsonResponse
    {
        $link = IntakeLink::findOrFail($id);
        // ข้อมูลลูกค้าที่เข้ามาทางลิงก์นี้ไม่ถูกลบตาม (intake_link_id เป็น null แทน)
        $link->delete();

        return $this->success(null, 'ลบลิงก์แล้ว');
    }

    // ── ข้อมูลที่ลูกค้ากรอกเข้ามา ────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = CustomerIntake::with(['schedule.trip', 'link'])
            ->withCount('people');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($scheduleId = $request->input('schedule_id')) {
            $query->where('trip_schedule_id', $scheduleId);
        }

        if ($search = trim((string) $request->input('search'))) {
            $digits = preg_replace('/\D/', '', $search) ?? '';
            $query->where(function ($q) use ($search, $digits) {
                $q->where('contact_name', 'like', "%{$search}%");
                if ($digits !== '') {
                    $q->orWhere('contact_phone', 'like', "%{$digits}%");
                }
            });
        }

        $intakes = $query->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->paginate(min((int) $request->input('per_page', 20), 100));

        $items = collect($intakes->items())->map(fn (CustomerIntake $intake) => $this->summaryPayload($intake));

        return $this->paginated(
            $intakes->setCollection($items),
            'สำเร็จ',
            ['new_count' => CustomerIntake::where('status', 'new')->count()],
        );
    }

    public function show(int $id): JsonResponse
    {
        $intake = CustomerIntake::with(['schedule.trip', 'link', 'people.pickupPoint'])->findOrFail($id);

        return $this->success([
            ...$this->summaryPayload($intake),
            'note' => $intake->note,
            'group_url' => $intake->groupUrl(),
            // ชุดที่หน้า "จองแทนลูกค้า" เอาไปเติมฟอร์มผู้โดยสารได้ตรง ๆ
            'passengers' => $intake->people->map->toPassengerPayload()->values(),
            'people' => $intake->people->map(fn ($person) => [
                'id' => $person->id,
                'is_lead' => $person->is_lead,
                'name' => $person->name,
                'nickname' => $person->nickname,
                'phone' => $person->phone,
                'filled_at' => $person->created_at?->toIso8601String(),
                // หลักฐานความยินยอม PDPA — ต้องหยิบให้ได้ตอนมีคนถาม
                'consent_at' => $person->consent_at?->toIso8601String(),
                // จุดขึ้นรถที่เจ้าตัวเลือกเอง — คนละคนในกลุ่มขึ้นคนละจุดได้
                'pickup_label' => $person->pickupPoint?->pickup_location,
            ])->values(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $intake = CustomerIntake::findOrFail($id);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(['new', 'booked', 'archived'])],
            'trip_schedule_id' => ['nullable', 'exists:trip_schedules,id'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:40'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $intake->fill($data)->save();

        return $this->success($this->summaryPayload($intake->fresh(['schedule.trip', 'link'])->loadCount('people')), 'บันทึกแล้ว');
    }

    public function destroy(int $id): JsonResponse
    {
        CustomerIntake::findOrFail($id)->delete();

        return $this->success(null, 'ลบข้อมูลลูกค้ากลุ่มนี้แล้ว');
    }

    public function destroyPerson(int $id, int $personId): JsonResponse
    {
        $intake = CustomerIntake::findOrFail($id);
        $intake->people()->where('id', $personId)->delete();

        return $this->success(null, 'ลบผู้เดินทางคนนี้แล้ว');
    }

    /** จำนวนที่ยังไม่ได้จัดการ — ป้ายตัวเลขบนเมนูซ้าย */
    public function summary(): JsonResponse
    {
        return $this->success(['new_count' => CustomerIntake::where('status', 'new')->count()]);
    }

    /** @return array<string, mixed> */
    private function linkPayload(IntakeLink $link): array
    {
        $schedule = $link->schedule;

        return [
            'id' => $link->id,
            'token' => $link->token,
            'url' => $link->publicUrl(),
            'label' => $link->label,
            'is_active' => $link->is_active,
            'uses_count' => $link->uses_count,
            'intakes_count' => $link->intakes_count ?? 0,
            'last_used_at' => $link->last_used_at?->toIso8601String(),
            'trip_schedule_id' => $link->trip_schedule_id,
            'schedule_label' => $schedule
                ? trim(($schedule->trip?->title ?? 'รอบเดินทาง').' · '.$schedule->departureLabelShort())
                : null,
            // ชิ้นส่วนแยกของป้ายเดียวกัน — หน้าแอดมินมีรอบเยอะจนอ่านเป็นบรรทัด
            // ข้อความไม่ทัน จึงวาดเป็นการ์ดรูป+วันที่แทน
            'schedule_trip_title' => $schedule?->trip?->title,
            'schedule_departure_date' => $schedule?->departure_date?->toDateString(),
            'schedule_image' => $schedule?->trip?->thumbnail_image ?: $schedule?->trip?->cover_image,
        ];
    }

    /** @return array<string, mixed> */
    private function summaryPayload(CustomerIntake $intake): array
    {
        $schedule = $intake->schedule;

        return [
            'id' => $intake->id,
            'status' => $intake->status,
            'contact_name' => $intake->contact_name,
            'contact_phone' => $intake->contact_phone,
            'contact_email' => $intake->contact_email,
            'party_size' => $intake->party_size,
            'filled_count' => $intake->people_count ?? $intake->people()->count(),
            'source' => $intake->source,
            'note_excerpt' => $intake->note ? mb_substr($intake->note, 0, 120) : null,
            'trip_schedule_id' => $intake->trip_schedule_id,
            'trip_id' => $schedule?->trip_id,
            'schedule_label' => $schedule
                ? trim(($schedule->trip?->title ?? 'รอบเดินทาง').' · '.$schedule->departureLabelShort())
                : null,
            'departure_date' => $schedule?->departure_date?->toDateString(),
            'link_label' => $intake->link?->label,
            'booking_id' => $intake->booking_id,
            'converted_at' => $intake->converted_at?->toIso8601String(),
            'last_activity_at' => $intake->last_activity_at?->toIso8601String(),
            'created_at' => $intake->created_at?->toIso8601String(),
        ];
    }
}
