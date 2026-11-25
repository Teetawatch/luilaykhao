<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TripSchedule;
use App\Models\TripSharedExpense;
use App\Models\TripSharedSettlement;
use App\Services\SharedExpenseService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * หารค่าใช้จ่ายกับเพื่อนร่วมทริป — ดู SharedExpenseService
 *
 * ทุกคำตอบคืนสรุปทั้งหน้า (present) หน้าจอจะได้ไม่ต้องคำนวณเอง
 */
class SharedExpenseController extends Controller
{
    use ApiResponse;

    public function __construct(private SharedExpenseService $expenses) {}

    public function index(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->findOrFail($scheduleId);

        return $this->respond(fn () => $this->expenses->present($request->user(), $schedule), 403);
    }

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $this->validateExpense($request);
        $schedule = TripSchedule::with('trip')->findOrFail($scheduleId);

        return $this->respond(function () use ($request, $schedule, $validated) {
            $this->expenses->create($request->user(), $schedule, $validated);

            return $this->expenses->present($request->user(), $schedule);
        }, 422, 'บันทึกแล้ว', 201);
    }

    public function update(Request $request, int $scheduleId, int $expenseId): JsonResponse
    {
        $validated = $this->validateExpense($request);
        $schedule = TripSchedule::with('trip')->findOrFail($scheduleId);

        return $this->respond(function () use ($request, $schedule, $expenseId, $validated) {
            $this->expenses->update($request->user(), $schedule, $expenseId, $validated);

            return $this->expenses->present($request->user(), $schedule);
        }, 422, 'แก้ไขแล้ว');
    }

    public function destroy(Request $request, int $scheduleId, int $expenseId): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->findOrFail($scheduleId);

        return $this->respond(function () use ($request, $schedule, $expenseId) {
            $this->expenses->delete($request->user(), $schedule, $expenseId);

            return $this->expenses->present($request->user(), $schedule);
        }, 422, 'ลบรายการแล้ว');
    }

    public function settle(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'from_passenger_id' => ['required', 'integer'],
            'to_passenger_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.SharedExpenseService::MAX_AMOUNT],
            'method' => ['nullable', Rule::in([TripSharedSettlement::METHOD_TRANSFER, TripSharedSettlement::METHOD_CASH])],
        ]);
        $schedule = TripSchedule::with('trip')->findOrFail($scheduleId);

        return $this->respond(function () use ($request, $schedule, $validated) {
            $this->expenses->settle(
                $request->user(),
                $schedule,
                (int) $validated['from_passenger_id'],
                (int) $validated['to_passenger_id'],
                $validated['amount'],
                $validated['method'] ?? TripSharedSettlement::METHOD_TRANSFER,
            );

            return $this->expenses->present($request->user(), $schedule);
        }, 422, 'บันทึกการจ่ายคืนแล้ว', 201);
    }

    public function unsettle(Request $request, int $scheduleId, int $settlementId): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->findOrFail($scheduleId);

        return $this->respond(function () use ($request, $schedule, $settlementId) {
            $this->expenses->unsettle($request->user(), $schedule, $settlementId);

            return $this->expenses->present($request->user(), $schedule);
        }, 422, 'ยกเลิกรายการจ่ายคืนแล้ว');
    }

    /** พร้อมเพย์รับเงินคืน — ใช้กับทุกทริป ส่งว่าง = เอาออก */
    public function promptPay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'promptpay_id' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-\s]+$/'],
        ]);

        $digits = preg_replace('/\D/', '', (string) ($validated['promptpay_id'] ?? ''));
        if ($digits !== '' && ! (strlen($digits) === 13 || (strlen($digits) === 10 && str_starts_with($digits, '0')))) {
            return $this->error('พร้อมเพย์ต้องเป็นเบอร์มือถือ 10 หลัก หรือเลข 13 หลัก', 422);
        }

        $this->expenses->setPromptPay($request->user(), $digits === '' ? null : $digits);

        return $this->success(['promptpay_id' => $digits === '' ? null : $digits], $digits === '' ? 'เอาพร้อมเพย์ออกแล้ว' : 'บันทึกพร้อมเพย์แล้ว');
    }

    /** @return array<string, mixed> */
    private function validateExpense(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:60'],
            'category' => ['nullable', Rule::in(TripSharedExpense::CATEGORIES)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.SharedExpenseService::MAX_AMOUNT],
            'payer_passenger_id' => ['required', 'integer'],
            'split_mode' => ['nullable', Rule::in([TripSharedExpense::SPLIT_EQUAL, TripSharedExpense::SPLIT_CUSTOM])],
            'passenger_ids' => ['nullable', 'array', 'max:'.SharedExpenseService::MAX_PEOPLE],
            'passenger_ids.*' => ['integer'],
            'shares' => ['nullable', 'array', 'max:'.SharedExpenseService::MAX_PEOPLE],
            'shares.*.passenger_id' => ['required_with:shares', 'integer'],
            'shares.*.amount' => ['required_with:shares', 'numeric', 'min:0', 'max:'.SharedExpenseService::MAX_AMOUNT],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
    }

    /**
     * @param  \Closure(): array<string, mixed>  $work
     */
    private function respond(\Closure $work, int $errorStatus, string $message = 'สำเร็จ', int $status = 200): JsonResponse
    {
        try {
            return $this->success($work(), $message, $status);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $errorStatus);
        }
    }
}
