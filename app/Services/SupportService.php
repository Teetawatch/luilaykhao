<?php

namespace App\Services;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * ศูนย์ช่วยเหลือในแอป (async support inbox) — ลูกค้าหนึ่งคนมีห้องสนทนาเดียวกับทีมงาน
 * ทีมงานตอบเมื่อว่าง ไม่ใช่ live chat เต็มรูปแบบ แต่มี realtime + push เมื่อมีข้อความใหม่
 */
class SupportService
{
    /** ข้อความต้อนรับอัตโนมัติเมื่อลูกค้าเปิดห้องครั้งแรก */
    public const WELCOME_BODY = 'สวัสดีค่ะ 👋 ทีมงานลุยเลเขายินดีช่วยเหลือค่ะ พิมพ์คำถามหรือเรื่องที่ต้องการสอบถามได้เลย ทีมงานจะรีบตอบกลับโดยเร็วที่สุดค่ะ';

    /**
     * ดึงห้องของลูกค้า (สร้างให้อัตโนมัติถ้ายังไม่มี) พร้อมข้อความต้อนรับ
     */
    public function conversationFor(User $user): SupportConversation
    {
        $conversation = SupportConversation::firstOrCreate(
            ['user_id' => $user->id],
            ['status' => 'open'],
        );

        $this->ensureWelcome($conversation);

        return $conversation;
    }

    /**
     * ใส่ข้อความต้อนรับจากระบบให้ห้องที่ยังว่าง เพื่อไม่ให้เปิดมาเจอจอเปล่า
     */
    public function ensureWelcome(SupportConversation $conversation): void
    {
        if ($conversation->messages()->exists()) {
            return;
        }

        $this->recordMessage($conversation, null, 'system', self::WELCOME_BODY, null);
    }

    /**
     * บันทึกข้อความใหม่ลงห้อง + อัปเดตข้อความล่าสุด และเลื่อนตัวชี้ "อ่านแล้ว" ของฝั่งผู้ส่ง
     */
    public function postMessage(
        SupportConversation $conversation,
        ?User $sender,
        string $senderRole,
        ?string $body,
        ?string $imagePath,
    ): SupportMessage {
        $message = $this->recordMessage($conversation, $sender?->id, $senderRole, $body, $imagePath);

        // ผู้ส่งถือว่าอ่านถึงข้อความของตัวเองแล้ว
        if ($senderRole === 'customer') {
            $conversation->forceFill(['customer_last_read_id' => $message->id])->save();
        } elseif ($senderRole === 'admin') {
            $conversation->forceFill(['admin_last_read_id' => $message->id])->save();
        }

        return $message;
    }

    /**
     * เลื่อนตัวชี้ "อ่านแล้ว" ของฝั่งที่ระบุ (customer|admin) ไปยังข้อความล่าสุด
     */
    public function markRead(SupportConversation $conversation, string $side, ?int $messageId = null): void
    {
        $latestId = $messageId ?: (int) $conversation->messages()->max('id');
        if ($latestId <= 0) {
            return;
        }

        $column = $side === 'admin' ? 'admin_last_read_id' : 'customer_last_read_id';
        if ((int) $conversation->{$column} >= $latestId) {
            return;
        }

        $conversation->forceFill([$column => $latestId])->save();
    }

    /** จำนวนข้อความที่ลูกค้ายังไม่อ่าน (ข้อความจากแอดมิน/ระบบ) */
    public function unreadForCustomer(SupportConversation $conversation): int
    {
        return $conversation->messages()
            ->where('id', '>', (int) $conversation->customer_last_read_id)
            ->where('sender_role', '!=', 'customer')
            ->count();
    }

    /** จำนวนข้อความที่ทีมงานยังไม่อ่าน (เฉพาะข้อความจากลูกค้า) */
    public function unreadForAdmin(SupportConversation $conversation): int
    {
        return $conversation->messages()
            ->where('id', '>', (int) $conversation->admin_last_read_id)
            ->where('sender_role', 'customer')
            ->count();
    }

    /** id ของแอดมิน/ผู้ดูแลทั้งหมด สำหรับส่ง push เมื่อลูกค้าทัก */
    public function adminRecipientIds(): Collection
    {
        // ใช้ whereHas แทน role() เพราะจะไม่โยน exception หาก role ใด role หนึ่งยังไม่ถูกสร้าง
        return User::whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'operator']))
            ->pluck('id');
    }

    /**
     * แปลงข้อความเป็น payload สำหรับ client
     *
     * @param  string|null  $viewerRole  มุมมองผู้อ่าน (customer|admin) เพื่อคำนวณ is_mine
     */
    public function presentMessage(SupportMessage $message, ?string $viewerRole = null): array
    {
        $author = $message->relationLoaded('user') ? $message->user : $message->user()->first();

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_role' => $message->sender_role,
            'body' => $message->body,
            'image_url' => $message->image_url,
            'created_at' => $message->created_at?->toISOString(),
            'is_mine' => $viewerRole !== null && $message->sender_role === $viewerRole,
            'sender' => $message->sender_role === 'system' || ! $author ? null : [
                'id' => $author->id,
                'name' => $author->name,
                'nickname' => $author->nickname,
                'avatar_url' => $author->avatar_url,
            ],
        ];
    }

    /** ข้อความย่อสำหรับแสดงในลิสต์ inbox / preview */
    public function previewText(SupportMessage $message): string
    {
        if (filled($message->body)) {
            return Str::limit($message->body, 100);
        }

        return $message->image_path ? '📷 รูปภาพ' : '';
    }

    /**
     * สร้างเรคคอร์ดข้อความและอัปเดต meta ของห้อง (ล่าสุด/พรีวิว/สถานะ)
     */
    private function recordMessage(
        SupportConversation $conversation,
        ?int $userId,
        string $senderRole,
        ?string $body,
        ?string $imagePath,
    ): SupportMessage {
        $body = $body !== null ? trim($body) : null;

        $message = $conversation->messages()->create([
            'user_id' => $userId,
            'sender_role' => $senderRole,
            'body' => $body !== '' ? $body : null,
            'image_path' => $imagePath,
        ]);

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
            'last_message_preview' => $this->previewText($message),
            // ลูกค้าทักเข้ามาถือว่าเปิดเคสใหม่ ให้เด้งกลับเป็น open เสมอ
            'status' => $senderRole === 'customer' ? 'open' : $conversation->status,
        ])->save();

        return $message;
    }
}
