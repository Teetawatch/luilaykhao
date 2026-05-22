<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\GroupPlan;
use App\Models\TripSchedule;
use App\Services\GroupPlanService;
use App\Support\GroupPlanPresenter;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupPlanController extends Controller
{
    use ApiResponse;

    public function __construct(
        private GroupPlanService $groupPlanService,
    ) {}

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'seat_count' => ['required', 'integer', 'min:1', 'max:20'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);

        try {
            $plan = $this->groupPlanService->create(
                $request->user(),
                $schedule,
                (int) $validated['seat_count'],
                $validated['name'] ?? null,
            );

            return $this->success(
                GroupPlanPresenter::present($plan, $request->user()->id),
                'สร้างกลุ่มเรียบร้อย แชร์ลิงก์ให้เพื่อนได้เลย',
                201,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $plan = $this->findByCode($code);

        return $this->success(GroupPlanPresenter::present($plan, $request->user()->id));
    }

    public function mine(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $plans = GroupPlan::with(['members.user', 'schedule.trip', 'booking'])
            ->whereIn('status', ['open', 'booked'])
            ->where(function ($q) use ($userId) {
                $q->where('host_user_id', $userId)
                    ->orWhereHas('members', fn ($m) => $m
                        ->where('user_id', $userId)
                        ->where('status', '!=', 'left'));
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (GroupPlan $plan) => GroupPlanPresenter::present($plan, $userId));

        return $this->success($plans);
    }

    public function join(Request $request, string $code): JsonResponse
    {
        $plan = $this->findByCode($code);

        try {
            $this->groupPlanService->join($plan, $request->user());

            return $this->success(
                GroupPlanPresenter::present($plan->fresh(['members.user', 'schedule.trip']), $request->user()->id),
                'เข้าร่วมกลุ่มแล้ว',
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function claimSeat(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'seat_id' => ['required', 'string', 'max:16'],
            'title' => ['nullable', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:120'],
            'allergies' => ['nullable', 'string', 'max:500'],
            'health_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $plan = $this->findByCode($code);

        try {
            $this->groupPlanService->claimSeat(
                $plan,
                $request->user(),
                $validated['seat_id'],
                $validated,
            );

            return $this->success(
                GroupPlanPresenter::present($plan->fresh(['members.user', 'schedule.trip']), $request->user()->id),
                'เลือกที่นั่งและบันทึกข้อมูลแล้ว',
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function releaseSeat(Request $request, string $code): JsonResponse
    {
        $plan = $this->findByCode($code);
        $this->groupPlanService->releaseSeat($plan, $request->user());

        return $this->success(
            GroupPlanPresenter::present($plan->fresh(['members.user', 'schedule.trip']), $request->user()->id),
            'ปล่อยที่นั่งแล้ว',
        );
    }

    public function leave(Request $request, string $code): JsonResponse
    {
        $plan = $this->findByCode($code);
        $this->groupPlanService->leave($plan, $request->user());

        return $this->success(null, 'ออกจากกลุ่มแล้ว');
    }

    public function checkout(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'pickup_point_id' => ['nullable', 'integer'],
            'pickup_region' => ['nullable', 'string', 'max:64'],
        ]);

        $plan = $this->findByCode($code);

        try {
            $booking = $this->groupPlanService->checkout(
                $plan,
                $request->user(),
                $validated['pickup_point_id'] ?? null,
                $validated['pickup_region'] ?? null,
            );

            return $this->success(
                new BookingResource($booking),
                'สร้างการจองกลุ่มสำเร็จ ดำเนินการชำระเงินได้เลย',
                201,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function cancel(Request $request, string $code): JsonResponse
    {
        $plan = $this->findByCode($code);

        if ($request->user()->id !== $plan->host_user_id) {
            return $this->error('เฉพาะหัวหน้ากลุ่มเท่านั้นที่ยกเลิกได้', 403);
        }

        $this->groupPlanService->cancel($plan);

        return $this->success(null, 'ยกเลิกกลุ่มแล้ว');
    }

    private function findByCode(string $code): GroupPlan
    {
        return GroupPlan::with(['members.user', 'schedule.trip', 'booking'])
            ->where('invite_code', strtoupper($code))
            ->firstOrFail();
    }
}
