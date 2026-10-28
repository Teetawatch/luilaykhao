<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PermitForm;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Services\PermitFormService;
use App\Support\PermitFormFields;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * แบบฟอร์มขออนุญาตของอุทยาน — อุทยานบางแห่งให้ผู้เดินทางทุกคนกรอกแบบคำขอ
 * ของตัวเอง แอดมินอัปโหลดฟอร์มครั้งเดียว วางช่องบนภาพ แล้วผูกกับทริป ระบบจะเติม
 * ข้อมูลที่ลูกค้ากรอกไว้ตอนจองให้ทุกคนในรอบ ช่องที่ไม่มีข้อมูลเว้นว่างให้เขียนเอง
 *
 * ไฟล์ที่ได้มีเลขบัตรประชาชน จึงสร้างใหม่ทุกครั้งที่กดโหลด ไม่เก็บไว้ที่ไหน
 */
class AdminPermitFormController extends Controller
{
    use ApiResponse;

    public function __construct(private PermitFormService $forms) {}

    public function index(): JsonResponse
    {
        $forms = PermitForm::with('trips:id,title,permit_form_id')
            ->orderByDesc('id')->get();

        return $this->success([
            'forms' => $forms->map(fn (PermitForm $f) => $this->present($f)),
            'trips' => Trip::orderBy('title')->get(['id', 'title', 'permit_form_id']),
            'catalog' => PermitFormFields::catalog(),
        ]);
    }

    /**
     * อัปโหลดแบบฟอร์มใหม่ — หน้าแอดมินแปลง PDF เป็นภาพรายหน้าเองแล้วส่ง `pages[]`
     * มาพร้อมขนาดกระดาษ (มม.) ส่วน `source` คือไฟล์ต้นฉบับเก็บไว้อ้างอิง
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'source' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
            'pages' => ['required', 'array', 'min:1', 'max:'.PermitFormService::MAX_PAGES],
            'pages.*' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
            'page_sizes' => ['required', 'array'],
            'page_sizes.*.width_mm' => ['required', 'numeric', 'min:50', 'max:1000'],
            'page_sizes.*.height_mm' => ['required', 'numeric', 'min:50', 'max:1000'],
        ]);

        $form = $this->forms->create(
            $data['name'],
            $request->file('pages'),
            $data['page_sizes'],
            $request->file('source'),
            $data['note'] ?? null,
        );

        return $this->success($this->present($form->load('trips:id,title,permit_form_id')), 'อัปโหลดแบบฟอร์มแล้ว', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $form = PermitForm::findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'fields' => ['sometimes', 'array', 'max:200'],
            'trip_ids' => ['sometimes', 'array'],
            'trip_ids.*' => ['integer', 'exists:trips,id'],
        ]);

        DB::transaction(function () use ($form, $data) {
            $form->fill(collect($data)->only(['name', 'note'])->all());

            if (array_key_exists('fields', $data)) {
                $form->fields = $this->forms->normalizeFields($data['fields'], $form);
            }

            $form->save();

            // ทริปหนึ่งใช้ได้ฟอร์มเดียว — ติ๊กที่นี่จึงย้ายทริปมาจากฟอร์มอื่นด้วย
            if (array_key_exists('trip_ids', $data)) {
                Trip::where('permit_form_id', $form->id)
                    ->whereNotIn('id', $data['trip_ids'])
                    ->update(['permit_form_id' => null]);
                Trip::whereIn('id', $data['trip_ids'])->update(['permit_form_id' => $form->id]);
            }
        });

        return $this->success($this->present($form->fresh()->load('trips:id,title,permit_form_id')), 'บันทึกแบบฟอร์มแล้ว');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->forms->delete(PermitForm::findOrFail($id));

        return $this->success(null, 'ลบแบบฟอร์มแล้ว');
    }

    /** ภาพหน้าที่ n (เริ่ม 1) — ไฟล์อยู่ในดิสก์ส่วนตัว หน้าแอดมินดึงผ่าน token */
    public function page(int $id, int $page): Response
    {
        $form = PermitForm::findOrFail($id);
        $entry = $form->pages[$page - 1] ?? abort(404);

        return Storage::disk(PermitFormService::DISK)->response($entry['path'], null, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** ตัวอย่างด้วยข้อมูลสมมติ — ส่ง `fields` ที่ยังไม่บันทึกมาดูก่อนได้ */
    public function preview(Request $request, int $id): Response
    {
        $form = PermitForm::findOrFail($id);
        $request->validate(['fields' => ['sometimes', 'array', 'max:200']]);

        $fields = $request->has('fields')
            ? $this->forms->normalizeFields($request->input('fields', []), $form)
            : null;

        return $this->pdf($this->forms->renderSample($form, $fields), 'ตัวอย่าง-'.$form->name.'.pdf');
    }

    /** ทั้งรอบ — หนึ่งชุดต่อผู้เดินทางหนึ่งคน */
    public function schedule(int $scheduleId): Response|JsonResponse
    {
        $schedule = TripSchedule::with('trip.permitForm')->findOrFail($scheduleId);
        $form = $schedule->trip?->permitForm;

        if (! $form) {
            return $this->error('ทริปนี้ยังไม่ได้ผูกแบบฟอร์มอุทยาน', 422);
        }

        $date = $schedule->departure_date?->toDateString() ?? 'round';

        return $this->pdf($this->forms->renderForSchedule($form, $schedule), "แบบฟอร์ม-{$form->name}-{$date}.pdf");
    }

    /** เฉพาะคนในใบจองเดียว — ส่งให้ลูกค้ากรอกช่องที่ยังว่าง */
    public function booking(string $ref): Response|JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)->with('schedule.trip.permitForm')->firstOrFail();
        $form = $booking->schedule?->trip?->permitForm;

        if (! $form) {
            return $this->error('ทริปนี้ยังไม่ได้ผูกแบบฟอร์มอุทยาน', 422);
        }

        return $this->pdf($this->forms->renderForBooking($form, $booking), "แบบฟอร์ม-{$form->name}-{$booking->booking_ref}.pdf");
    }

    private function pdf(string $bytes, string $filename): Response
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'permit-form.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$ascii}\"; filename*=UTF-8''".rawurlencode($filename),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function present(PermitForm $form): array
    {
        return [
            'id' => $form->id,
            'name' => $form->name,
            'note' => $form->note,
            'source_name' => $form->source_name,
            'pages' => collect($form->pages)->map(fn (array $p, int $i) => [
                'number' => $i + 1,
                'width_mm' => $p['width_mm'],
                'height_mm' => $p['height_mm'],
            ])->values(),
            'fields' => $form->fields ?? [],
            'trips' => $form->relationLoaded('trips')
                ? $form->trips->map(fn (Trip $t) => ['id' => $t->id, 'title' => $t->title])->values()
                : [],
            'updated_at' => $form->updated_at,
        ];
    }
}
