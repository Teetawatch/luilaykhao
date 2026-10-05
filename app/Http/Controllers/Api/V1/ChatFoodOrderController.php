<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendChatPushJob;
use App\Models\ChatFoodOrder;
use App\Models\ChatFoodRound;
use App\Models\ChatMessage;
use App\Models\TripSchedule;
use App\Services\ChatFoodOrderService;
use App\Services\ChatService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * รับออเดอร์อาหารในห้องแชททริป — สตาฟเปิดรอบ ลูกทริปพิมพ์เมนูของตัวเอง
 * สตาฟเปิดรายการรวมแล้วไปสั่งร้านทีเดียว (ดู ChatFoodOrderService)
 */
class ChatFoodOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatService $chatService,
        private ChatFoodOrderService $food,
    ) {}

    /** เปิดรอบรับออเดอร์ — เฉพาะสตาฟประจำรอบ/แอดมิน */
    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:300'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:'.ChatFoodRound::MAX_MINUTES],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canModerate($user, $schedule)) {
            return $this->error('เปิดรับออเดอร์อาหารได้เฉพาะทีมงานประจำรอบ', 403);
        }

        $round = $this->food->open(
            $user,
            $schedule,
            trim($validated['title']),
            isset($validated['note']) ? trim($validated['note']) : null,
            $validated['duration_minutes'] ?? null,
        );

        $message = ChatMessage::with([
            'user:id,name,nickname,avatar',
            'replyTo.user:id,name,nickname,avatar',
            'reactions:id,message_id,user_id,emoji',
            'foodRound.orders.user:id,name,nickname,avatar',
        ])->find($round->message_id);

        // ต้องได้คำตอบจากทุกคนก่อนถึงร้าน — เด้งมีเสียงเหมือนโหวต
        if ($message) {
            $this->chatService->markRead($user, $schedule, $message->id);
            SendChatPushJob::dispatch($message->id, $user->id, [], true);
        }

        return $this->success(
            $message ? $this->chatService->presentMessage($message, $user->id) : null,
            'เปิดรับออเดอร์แล้ว',
            201,
        );
    }

    /** ส่ง/แก้ออเดอร์ของตัวเอง (skipped=true = รอบนี้ไม่สั่ง) */
    public function upsertMine(Request $request, int $scheduleId, int $roundId): JsonResponse
    {
        $validated = $request->validate([
            'skipped' => ['nullable', 'boolean'],
            'items' => ['nullable', 'array', 'max:'.ChatFoodRound::MAX_ITEMS],
            'items.*.name' => ['required', 'string', 'max:120'],
            'items.*.qty' => ['nullable', 'integer', 'min:1', 'max:'.ChatFoodRound::MAX_QTY],
        ]);

        [$schedule, $round, $error] = $this->resolve($request, $scheduleId, $roundId);
        if ($error) {
            return $error;
        }

        try {
            $round = $this->food->submit(
                $request->user(),
                $round,
                $validated['items'] ?? [],
                (bool) ($validated['skipped'] ?? false),
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($round, 'บันทึกออเดอร์แล้ว');
    }

    /** ถอนออเดอร์ของตัวเอง */
    public function destroyMine(Request $request, int $scheduleId, int $roundId): JsonResponse
    {
        [$schedule, $round, $error] = $this->resolve($request, $scheduleId, $roundId);
        if ($error) {
            return $error;
        }

        $order = ChatFoodOrder::where('round_id', $round->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $order) {
            return $this->payload($round, 'ยังไม่มีออเดอร์');
        }

        try {
            $round = $this->food->remove($order);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($round, 'ยกเลิกออเดอร์แล้ว');
    }

    /** สตาฟจดออเดอร์แทนคนที่ไม่ได้ใช้แอป */
    public function storeOnBehalf(Request $request, int $scheduleId, int $roundId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'items' => ['required', 'array', 'min:1', 'max:'.ChatFoodRound::MAX_ITEMS],
            'items.*.name' => ['required', 'string', 'max:120'],
            'items.*.qty' => ['nullable', 'integer', 'min:1', 'max:'.ChatFoodRound::MAX_QTY],
        ]);

        [$schedule, $round, $error] = $this->resolve($request, $scheduleId, $roundId, staffOnly: true);
        if ($error) {
            return $error;
        }

        try {
            $round = $this->food->addOnBehalf($request->user(), $round, trim($validated['name']), $validated['items']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($round, 'จดออเดอร์แทนแล้ว');
    }

    /** ลบออเดอร์ใดก็ได้ในรอบ — เจ้าของออเดอร์ หรือสตาฟ */
    public function destroyOrder(Request $request, int $scheduleId, int $roundId, int $orderId): JsonResponse
    {
        [$schedule, $round, $error] = $this->resolve($request, $scheduleId, $roundId);
        if ($error) {
            return $error;
        }

        $order = ChatFoodOrder::where('round_id', $round->id)->findOrFail($orderId);
        $user = $request->user();

        if ((int) $order->user_id !== (int) $user->id && ! $this->chatService->canModerate($user, $schedule)) {
            return $this->error('ลบได้เฉพาะออเดอร์ของตัวเอง', 403);
        }

        try {
            $round = $this->food->remove($order);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($round, 'ลบออเดอร์แล้ว');
    }

    /** สตาฟใส่ราคาต่อเมนู + พร้อมเพย์ที่ให้โอนคืน (notify = ประกาศยอดให้ทุกคน) */
    public function bill(Request $request, int $scheduleId, int $roundId): JsonResponse
    {
        $validated = $request->validate([
            'prices' => ['present', 'array', 'max:100'],
            'prices.*.name' => ['required', 'string', 'max:120'],
            'prices.*.price' => ['required', 'numeric', 'min:0', 'max:'.ChatFoodRound::MAX_PRICE],
            // เบอร์มือถือ 10 หลัก หรือเลขบัตร/เลขผู้เสียภาษี 13 หลัก (ขีด/เว้นวรรคได้)
            'promptpay_id' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-\s]+$/'],
            'payee_name' => ['nullable', 'string', 'max:80'],
            'notify' => ['nullable', 'boolean'],
        ]);

        $digits = preg_replace('/\D/', '', (string) ($validated['promptpay_id'] ?? ''));
        if ($digits !== '' && ! (strlen($digits) === 13 || (strlen($digits) === 10 && str_starts_with($digits, '0')))) {
            return $this->error('พร้อมเพย์ต้องเป็นเบอร์มือถือ 10 หลัก หรือเลข 13 หลัก', 422);
        }

        [$schedule, $round, $error] = $this->resolve($request, $scheduleId, $roundId, staffOnly: true);
        if ($error) {
            return $error;
        }

        $round = $this->food->bill(
            $request->user(),
            $round,
            $validated['prices'],
            $digits !== '' ? $digits : null,
            isset($validated['payee_name']) ? trim($validated['payee_name']) : null,
            (bool) ($validated['notify'] ?? false),
        );

        return $this->payload($round, ($validated['notify'] ?? false) ? 'ส่งยอดให้ทุกคนแล้ว' : 'บันทึกราคาแล้ว');
    }

    /** ลูกค้าแจ้งว่าโอนแล้ว (claimed=false = ถอน) */
    public function claimPaid(Request $request, int $scheduleId, int $roundId): JsonResponse
    {
        $validated = $request->validate(['claimed' => ['nullable', 'boolean']]);

        [$schedule, $round, $error] = $this->resolve($request, $scheduleId, $roundId);
        if ($error) {
            return $error;
        }

        try {
            $round = $this->food->claimPaid($request->user(), $round, (bool) ($validated['claimed'] ?? true));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($round, 'แจ้งทีมงานแล้ว');
    }

    /** สตาฟยืนยันว่าได้รับเงินของออเดอร์นี้แล้ว (หรือยกเลิก) */
    public function setPaid(Request $request, int $scheduleId, int $roundId, int $orderId): JsonResponse
    {
        $validated = $request->validate(['paid' => ['required', 'boolean']]);

        [$schedule, $round, $error] = $this->resolve($request, $scheduleId, $roundId, staffOnly: true);
        if ($error) {
            return $error;
        }

        $order = ChatFoodOrder::where('round_id', $round->id)->findOrFail($orderId);

        try {
            $round = $this->food->setPaid($request->user(), $order, (bool) $validated['paid']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($round, $validated['paid'] ? 'บันทึกว่าจ่ายแล้ว' : 'ยกเลิกแล้ว');
    }

    public function close(Request $request, int $scheduleId, int $roundId): JsonResponse
    {
        [$schedule, $round, $error] = $this->resolve($request, $scheduleId, $roundId, staffOnly: true);
        if ($error) {
            return $error;
        }

        return $this->payload($this->food->close($round), 'ปิดรับออเดอร์แล้ว');
    }

    public function reopen(Request $request, int $scheduleId, int $roundId): JsonResponse
    {
        [$schedule, $round, $error] = $this->resolve($request, $scheduleId, $roundId, staffOnly: true);
        if ($error) {
            return $error;
        }

        return $this->payload($this->food->reopen($round), 'เปิดรับออเดอร์ต่อแล้ว');
    }

    /**
     * @return array{0: ?TripSchedule, 1: ?ChatFoodRound, 2: ?JsonResponse}
     */
    private function resolve(Request $request, int $scheduleId, int $roundId, bool $staffOnly = false): array
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return [null, null, $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403)];
        }

        if ($staffOnly && ! $this->chatService->canModerate($user, $schedule)) {
            return [null, null, $this->error('ทำได้เฉพาะทีมงานประจำรอบ', 403)];
        }

        $round = ChatFoodRound::where('schedule_id', $scheduleId)->findOrFail($roundId);

        return [$schedule, $round, null];
    }

    private function payload(ChatFoodRound $round, string $message): JsonResponse
    {
        return $this->success([
            'message_id' => (int) $round->message_id,
            'food_round' => $this->food->present($round),
        ], $message);
    }
}
