<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CharterRequestResource;
use App\Models\CharterRequest;
use App\Models\Trip;
use App\Services\CharterRequestService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * เหมาทริป / จัดทริปส่วนตัว ฝั่งลูกค้า — ขอ ดูใบเสนอราคา ตอบรับ/ปฏิเสธ ยกเลิก
 */
class CharterRequestController extends Controller
{
    use ApiResponse;

    /** คำขอที่ยังเปิดอยู่พร้อมกันได้ไม่เกินนี้ต่อบัญชี — กันกดส่งซ้ำรัว ๆ */
    private const MAX_OPEN = 5;

    public function __construct(private CharterRequestService $charters) {}

    public function index(Request $request): JsonResponse
    {
        $items = CharterRequest::with(['trip', 'quoteTrip', 'booking'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return $this->success(CharterRequestResource::collection($items));
    }

    public function store(Request $request): JsonResponse
    {
        $today = now('Asia/Bangkok')->toDateString();

        $data = $request->validate([
            'trip_id' => ['nullable', 'integer', Rule::exists('trips', 'id')],
            'destination' => ['nullable', 'required_without:trip_id', 'string', 'max:150'],
            'preferred_date' => ['required', 'date', 'after:'.$today],
            'alternate_date' => ['nullable', 'date', 'after:'.$today],
            'flexible_dates' => ['nullable', 'boolean'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'group_size' => ['required', 'integer', 'min:'.CharterRequest::MIN_GROUP_SIZE, 'max:'.CharterRequest::MAX_GROUP_SIZE],
            'pickup_area' => ['nullable', 'string', 'max:150'],
            'budget_per_person' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'group_type' => ['nullable', Rule::in(CharterRequest::GROUP_TYPES)],
            'needs_tax_invoice' => ['nullable', 'boolean'],
            'contact_name' => ['required', 'string', 'max:150'],
            'contact_phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{8,30}$/'],
            'contact_line' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'destination.required_without' => 'กรุณาเลือกทริปหรือบอกปลายทางที่อยากไป',
            'preferred_date.after' => 'วันเดินทางต้องเป็นวันในอนาคต',
            'alternate_date.after' => 'วันสำรองต้องเป็นวันในอนาคต',
            'group_size.min' => 'เหมาทริปรับกลุ่มตั้งแต่ '.CharterRequest::MIN_GROUP_SIZE.' คนขึ้นไป (กลุ่มเล็กกว่านี้จองรอบปกติได้เลย)',
            'group_size.max' => 'กลุ่มใหญ่กว่า '.CharterRequest::MAX_GROUP_SIZE.' คน กรุณาทักทีมงานโดยตรง',
            'contact_phone.regex' => 'เบอร์โทรไม่ถูกต้อง',
        ]);

        if (! empty($data['trip_id']) && ! Trip::whereKey($data['trip_id'])->where('status', 'active')->exists()) {
            return $this->error('ทริปนี้ปิดรับแล้ว เลือกทริปอื่นหรือบอกปลายทางที่อยากไปได้เลย', 422);
        }

        $open = CharterRequest::where('user_id', $request->user()->id)
            ->whereIn('status', [CharterRequest::STATUS_NEW, CharterRequest::STATUS_QUOTED, CharterRequest::STATUS_ACCEPTED])
            ->count();
        if ($open >= self::MAX_OPEN) {
            return $this->error('มีคำขอที่ยังเปิดอยู่ '.$open.' รายการ รอทีมงานตอบหรือยกเลิกรายการเดิมก่อนนะครับ', 422);
        }

        $charter = $this->charters->create($request->user(), $data);

        return $this->success(
            new CharterRequestResource($charter->load(['trip', 'quoteTrip', 'booking'])),
            'ส่งคำขอเหมาทริปแล้ว ทีมงานจะติดต่อกลับพร้อมใบเสนอราคาภายใน 1–2 วันทำการ',
            201,
        );
    }

    public function show(Request $request, int $charter): JsonResponse
    {
        return $this->success(new CharterRequestResource($this->find($request, $charter)));
    }

    public function accept(Request $request, int $charter): JsonResponse
    {
        $model = $this->find($request, $charter);

        try {
            $model = $this->charters->accept($model);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            new CharterRequestResource($model->load(['trip', 'quoteTrip', 'booking'])),
            'ตอบรับใบเสนอราคาแล้ว ทีมงานจะเปิดรอบเดินทางของกลุ่มและส่งการจองให้ชำระเงินเร็ว ๆ นี้',
        );
    }

    public function decline(Request $request, int $charter): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $model = $this->find($request, $charter);

        try {
            $model = $this->charters->decline($model, $data['reason'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            new CharterRequestResource($model->load(['trip', 'quoteTrip', 'booking'])),
            'แจ้งทีมงานแล้ว ถ้าอยากให้ปรับราคาหรือรายละเอียด ทีมงานจะเสนอใหม่ให้ครับ',
        );
    }

    public function cancel(Request $request, int $charter): JsonResponse
    {
        $model = $this->find($request, $charter);

        try {
            $model = $this->charters->cancel($model);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(new CharterRequestResource($model->load(['trip', 'quoteTrip', 'booking'])), 'ยกเลิกคำขอแล้ว');
    }

    private function find(Request $request, int $id): CharterRequest
    {
        return CharterRequest::with(['trip', 'quoteTrip', 'booking'])
            ->whereKey($id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }
}
