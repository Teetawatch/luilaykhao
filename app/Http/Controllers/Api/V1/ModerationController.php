<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\ModerationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * รายงานเนื้อหา + บล็อกผู้ใช้ — เครื่องมือที่ผู้ใช้ใช้ดูแลพื้นที่ของตัวเอง
 *
 * ปลายทางเดียวรับได้ทุกชนิดเนื้อหา (แชท รีวิว โพสต์ คอมเมนต์ ผู้ใช้)
 * เพื่อให้ฝั่งแอปมีชีตรายงานใบเดียวใช้ได้ทุกหน้า
 */
class ModerationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ModerationService $moderation,
    ) {}

    /**
     * ตัวเลือกเหตุผล — แอปดึงไปเรนเดอร์ จะได้ไม่ต้องฝังรายการไว้สองที่
     */
    public function reasons(): JsonResponse
    {
        return $this->success([
            'reasons' => collect(ModerationService::REASONS)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(array_keys(ModerationService::TYPES))],
            'id' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', Rule::in(array_keys(ModerationService::REASONS))],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $this->moderation->report(
                $request->user(),
                $validated['type'],
                (int) $validated['id'],
                $validated['reason'] ?? null,
                $validated['note'] ?? null,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(null, 'ขอบคุณที่แจ้งเข้ามา ทีมงานจะตรวจสอบโดยเร็ว');
    }

    public function blocks(Request $request): JsonResponse
    {
        $blocks = UserBlock::where('blocker_id', $request->user()->id)
            ->with('blocked:id,name,nickname,avatar')
            ->latest('id')
            ->get()
            ->map(fn (UserBlock $block) => [
                'user_id' => $block->blocked_id,
                'name' => $block->blocked?->nickname ?: $block->blocked?->name,
                'avatar_url' => $block->blocked?->avatar_url,
                'blocked_at' => $block->created_at?->toISOString(),
            ])
            ->all();

        return $this->success(['blocks' => $blocks]);
    }

    public function block(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        try {
            $this->moderation->block($request->user(), (int) $validated['user_id']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(null, 'บล็อกผู้ใช้นี้แล้ว คุณจะไม่เห็นเนื้อหาของกันและกันอีก');
    }

    public function unblock(Request $request, int $userId): JsonResponse
    {
        $this->moderation->unblock($request->user(), $userId);

        return $this->success(null, 'เลิกบล็อกแล้ว');
    }

    // ── แอดมิน (role:admin|operator ผ่าน route middleware) ────────

    /**
     * คิวงานตรวจเนื้อหา — ค้างอยู่ก่อน เก่าสุดก่อน เพราะรายงานที่ค้างนานที่สุด
     * คือรายงานที่ผู้ใช้รอคำตอบนานที่สุด
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $reports = ContentReport::query()
            ->with(['reporter:id,name,nickname', 'author:id,name,nickname', 'resolver:id,name'])
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('type'), fn ($q, $type) => $q->where('reportable_type', $type))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->paginate(min((int) $request->input('per_page', 20), 50));

        $reports->getCollection()->transform(fn (ContentReport $report) => [
            'id' => $report->id,
            'type' => $report->reportable_type,
            'target_id' => $report->reportable_id,
            'reason' => $report->reason,
            'reason_label' => ModerationService::REASONS[$report->reason] ?? 'อื่น ๆ',
            'note' => $report->note,
            'status' => $report->status,
            'reporter' => $report->reporter ? [
                'id' => $report->reporter->id,
                'name' => $report->reporter->nickname ?: $report->reporter->name,
            ] : null,
            'author' => $report->author ? [
                'id' => $report->author->id,
                'name' => $report->author->nickname ?: $report->author->name,
            ] : null,
            'preview' => $this->moderation->preview($report),
            'resolved_by' => $report->resolver?->name,
            'resolved_at' => $report->resolved_at?->toISOString(),
            'created_at' => $report->created_at?->toISOString(),
        ]);

        $response = $this->paginated($reports);
        $data = $response->getData(true);
        $data['meta']['counts'] = [
            'open' => ContentReport::open()->count(),
            'total' => ContentReport::count(),
        ];

        return response()->json($data);
    }

    /**
     * ตัดสินรายงานหนึ่งใบ — hide (ผิดจริง), unhide (เอากลับคืน), dismiss (ไม่ผิด)
     */
    public function adminResolve(Request $request, int $reportId): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['hide', 'unhide', 'dismiss'])],
        ]);

        $report = ContentReport::findOrFail($reportId);
        $adminId = $request->user()->id;

        match ($validated['action']) {
            'hide' => $this->moderation->hide($report->reportable_type, $report->reportable_id, $adminId),
            'unhide' => $this->moderation->unhide($report->reportable_type, $report->reportable_id),
            default => null,
        };

        $this->moderation->resolve(
            $report,
            $validated['action'] === 'dismiss' ? ContentReport::STATUS_DISMISSED : ContentReport::STATUS_ACTIONED,
            $adminId,
        );

        return $this->success(null, match ($validated['action']) {
            'hide' => 'ซ่อนเนื้อหาแล้ว',
            'unhide' => 'แสดงเนื้อหาอีกครั้งแล้ว',
            default => 'ปิดรายงานแล้ว',
        });
    }

    /**
     * ประวัติการถูกรายงานของผู้ใช้คนหนึ่ง — ใช้ตัดสินใจว่าเป็นเรื่องครั้งเดียว
     * หรือเป็นคนที่ก่อเรื่องซ้ำ
     */
    public function adminUserHistory(int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);

        return $this->success([
            'user' => [
                'id' => $user->id,
                'name' => $user->nickname ?: $user->name,
                'email' => $user->email,
            ],
            'reports_received' => ContentReport::where('author_id', $user->id)->count(),
            'reports_open' => ContentReport::where('author_id', $user->id)->open()->count(),
            'times_blocked' => UserBlock::where('blocked_id', $user->id)->count(),
        ]);
    }
}
