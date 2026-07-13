<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\SupportInboxUpdated;
use App\Events\SupportMessageSent;
use App\Http\Controllers\Controller;
use App\Jobs\SendSupportPushJob;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Services\SupportService;
use App\Support\MediaDisk;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ศูนย์ช่วยเหลือในแอป (async support inbox)
 * - ฝั่งลูกค้า: หนึ่งคนหนึ่งห้อง คุยกับทีมงาน
 * - ฝั่งทีมงาน (admin|operator): กล่องข้อความรวมทุกห้อง ตอบเมื่อว่าง
 */
class SupportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private SupportService $support,
    ) {}

    // ───────────────────────── ฝั่งลูกค้า ─────────────────────────

    /** ข้อมูลห้องของลูกค้า (สร้างให้อัตโนมัติถ้ายังไม่มี) */
    public function show(Request $request): JsonResponse
    {
        $conversation = $this->support->conversationFor($request->user());

        return $this->success($this->conversationMeta($conversation, 'customer'));
    }

    /** ประวัติข้อความของห้องลูกค้า (รองรับ before_id เลื่อนย้อน / after_id poll ของใหม่) */
    public function messages(Request $request): JsonResponse
    {
        $conversation = $this->support->conversationFor($request->user());

        return $this->listMessages($request, $conversation, 'customer');
    }

    /** ลูกค้าส่งข้อความหาทีมงาน */
    public function send(Request $request): JsonResponse
    {
        $validated = $this->validateMessage($request);
        $conversation = $this->support->conversationFor($request->user());

        $message = $this->post($request, $conversation, $request->user(), 'customer', $validated);

        return $this->success(
            $this->support->presentMessage($message, 'customer'),
            'ส่งข้อความสำเร็จ',
            201,
        );
    }

    /** ลูกค้าอ่านถึงข้อความล่าสุดแล้ว */
    public function markRead(Request $request): JsonResponse
    {
        $conversation = $this->support->conversationFor($request->user());
        $this->support->markRead($conversation, 'customer', (int) $request->input('message_id') ?: null);

        return $this->success(['unread' => 0], 'อ่านแล้ว');
    }

    /** จำนวนข้อความที่ลูกค้ายังไม่อ่าน (สำหรับ badge) */
    public function unreadCount(Request $request): JsonResponse
    {
        $conversation = SupportConversation::where('user_id', $request->user()->id)->first();

        return $this->success([
            'count' => $conversation ? $this->support->unreadForCustomer($conversation) : 0,
        ]);
    }

    // ───────────────────────── ฝั่งทีมงาน ─────────────────────────

    /** รายการห้องช่วยเหลือทั้งหมด เรียงตามความเคลื่อนไหวล่าสุด พร้อมจำนวนที่ยังไม่ตอบ */
    public function adminIndex(Request $request): JsonResponse
    {
        $status = $request->get('status'); // open|closed|null(=all)

        $conversations = SupportConversation::query()
            ->with('user:id,name,nickname,avatar,phone')
            ->when(in_array($status, ['open', 'closed'], true), fn ($q) => $q->where('status', $status))
            ->whereNotNull('last_message_at')
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        return $this->success(
            $conversations->map(fn ($c) => $this->adminSummary($c))->all(),
        );
    }

    /** จำนวนห้องที่มีข้อความค้างยังไม่ตอบ (badge ในเมนูแอดมิน) */
    public function adminUnreadTotal(Request $request): JsonResponse
    {
        // ห้องที่มีข้อความจากลูกค้าใหม่กว่าตัวชี้ "อ่านแล้ว" ของทีมงาน = ยังค้างตอบ
        $count = SupportConversation::query()
            ->whereHas('messages', fn ($q) => $q
                ->whereColumn('support_messages.id', '>', 'support_conversations.admin_last_read_id')
                ->where('sender_role', 'customer'))
            ->count();

        return $this->success(['count' => $count]);
    }

    /** เปิดห้องหนึ่ง: meta + ประวัติข้อความ และเลื่อน "อ่านแล้ว" ฝั่งทีมงาน */
    public function adminShow(Request $request, int $conversationId): JsonResponse
    {
        $conversation = SupportConversation::with('user:id,name,nickname,avatar,phone,email')
            ->findOrFail($conversationId);

        $response = $this->listMessages($request, $conversation, 'admin');

        // เปิดห้อง = อ่านทั้งหมดแล้ว (เฉพาะเมื่อโหลดหน้าแรก ไม่ใช่ตอน poll)
        if (! $request->filled('after_id')) {
            $this->support->markRead($conversation, 'admin');
        }

        $payload = $response->getData(true);
        $payload['data']['conversation'] = $this->conversationMeta($conversation->fresh(), 'admin', withUser: true);

        return $this->success($payload['data'], $payload['message'] ?? 'สำเร็จ');
    }

    /** ทีมงานอ่านถึงข้อความล่าสุดแล้ว (ใช้ตอนมีข้อความเข้ามาระหว่างเปิดห้องอยู่) */
    public function adminMarkRead(Request $request, int $conversationId): JsonResponse
    {
        $conversation = SupportConversation::findOrFail($conversationId);
        $this->support->markRead($conversation, 'admin', (int) $request->input('message_id') ?: null);

        return $this->success(['unread' => 0], 'อ่านแล้ว');
    }

    /** ทีมงานตอบลูกค้า */
    public function adminReply(Request $request, int $conversationId): JsonResponse
    {
        $validated = $this->validateMessage($request);
        $conversation = SupportConversation::findOrFail($conversationId);

        $message = $this->post($request, $conversation, $request->user(), 'admin', $validated);

        return $this->success(
            $this->support->presentMessage($message, 'admin'),
            'ส่งข้อความสำเร็จ',
            201,
        );
    }

    /** ปิดเคส */
    public function adminClose(Request $request, int $conversationId): JsonResponse
    {
        $conversation = SupportConversation::findOrFail($conversationId);
        $conversation->forceFill(['status' => 'closed'])->save();
        broadcast(new SupportInboxUpdated($conversation));

        return $this->success($this->conversationMeta($conversation, 'admin', withUser: true), 'ปิดเคสแล้ว');
    }

    /** เปิดเคสใหม่ */
    public function adminReopen(Request $request, int $conversationId): JsonResponse
    {
        $conversation = SupportConversation::findOrFail($conversationId);
        $conversation->forceFill(['status' => 'open'])->save();
        broadcast(new SupportInboxUpdated($conversation));

        return $this->success($this->conversationMeta($conversation, 'admin', withUser: true), 'เปิดเคสอีกครั้งแล้ว');
    }

    // ───────────────────────── ภายใน ─────────────────────────

    private function validateMessage(Request $request): array
    {
        return $request->validate([
            'body' => ['nullable', 'string', 'max:2000', 'required_without:image'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);
    }

    /**
     * บันทึกข้อความ + broadcast (realtime), เด้ง inbox ทีมงาน และคิว push หาอีกฝั่ง
     */
    private function post(Request $request, SupportConversation $conversation, $sender, string $role, array $validated): SupportMessage
    {
        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store('support/'.date('Y/m'), MediaDisk::name())
            : null;

        $message = $this->support->postMessage(
            $conversation,
            $sender,
            $role,
            $validated['body'] ?? null,
            $imagePath,
        );

        broadcast(new SupportMessageSent($message))->toOthers();
        broadcast(new SupportInboxUpdated($conversation->fresh()));

        SendSupportPushJob::dispatch($message->id);

        return $message;
    }

    /**
     * ลิสต์ข้อความแบบเดียวกับห้องแชท: before_id เลื่อนย้อน, after_id ดึงของใหม่
     */
    private function listMessages(Request $request, SupportConversation $conversation, string $viewerRole): JsonResponse
    {
        $perPage = min(50, max(10, (int) $request->get('per_page', 30)));
        $beforeId = (int) $request->get('before_id', 0);
        $afterId = (int) $request->get('after_id', 0);

        if ($afterId > 0) {
            $messages = $conversation->messages()
                ->with('user:id,name,nickname,avatar')
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($perPage)
                ->get();

            return $this->success([
                'messages' => $messages->map(fn ($m) => $this->support->presentMessage($m, $viewerRole))->all(),
                'has_more' => false,
            ]);
        }

        $messages = $conversation->messages()
            ->with('user:id,name,nickname,avatar')
            ->when($beforeId > 0, fn ($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($perPage)
            ->get()
            ->reverse()
            ->values();

        return $this->success([
            'messages' => $messages->map(fn ($m) => $this->support->presentMessage($m, $viewerRole))->all(),
            'has_more' => $messages->count() === $perPage,
        ]);
    }

    private function conversationMeta(SupportConversation $conversation, string $viewerRole, bool $withUser = false): array
    {
        $meta = [
            'id' => $conversation->id,
            'status' => $conversation->status,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'unread_count' => $viewerRole === 'admin'
                ? $this->support->unreadForAdmin($conversation)
                : $this->support->unreadForCustomer($conversation),
        ];

        if ($withUser) {
            $user = $conversation->relationLoaded('user') ? $conversation->user : $conversation->user()->first();
            $meta['user'] = $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'nickname' => $user->nickname,
                'avatar_url' => $user->avatar_url,
                'phone' => $user->phone,
                'email' => $user->email,
            ] : null;
        }

        return $meta;
    }

    private function adminSummary(SupportConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'status' => $conversation->status,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'last_message_preview' => $conversation->last_message_preview,
            'unread_count' => $this->support->unreadForAdmin($conversation),
            'user' => [
                'id' => $conversation->user?->id,
                'name' => $conversation->user?->name,
                'nickname' => $conversation->user?->nickname,
                'avatar_url' => $conversation->user?->avatar_url,
                'phone' => $conversation->user?->phone,
            ],
        ];
    }
}
