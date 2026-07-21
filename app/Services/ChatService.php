<?php

namespace App\Services;

use App\Events\ChatMessageSent;
use App\Events\ChatMessageUpdated;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\ChatMessage;
use App\Models\ChatReaction;
use App\Models\ChatRead;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatService
{
    /** อีโมจิที่อนุญาตให้รีแอกชันได้ */
    public const REACTION_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    /**
     * ผู้ใช้เข้าถึงห้องแชทของรอบเดินทางนี้ได้หรือไม่
     * - มีการจอง active (pending/confirmed) ในรอบนี้, หรือ
     * - เป็นสตาฟที่ถูก assign ในรอบนี้, หรือ
     * - เป็น admin / operator
     */
    public function canAccess(User $user, TripSchedule $schedule): bool
    {
        if ($user->hasAnyRole(['admin', 'operator'])) {
            return true;
        }

        if ($this->isAssignedStaff($user, $schedule)) {
            return true;
        }

        $isOwner = Booking::where('schedule_id', $schedule->id)
            ->where('user_id', $user->id)
            ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
            ->exists();

        if ($isOwner) {
            return true;
        }

        // เพื่อนที่ถูกเชิญเข้าการจอง (companion) ก็เข้าห้องแชทของรอบนี้ได้
        return BookingMember::where('user_id', $user->id)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->whereHas('booking', fn ($q) => $q
                ->where('schedule_id', $schedule->id)
                ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES))
            ->exists();
    }

    /**
     * บทบาทของผู้ส่งในห้องนี้ — ใช้แสดงป้ายกำกับฝั่ง client
     */
    public function senderRole(User $user, TripSchedule $schedule): string
    {
        if ($this->isAssignedStaff($user, $schedule)) {
            return 'staff';
        }

        if ($user->hasAnyRole(['admin', 'operator'])) {
            return 'admin';
        }

        return 'customer';
    }

    /**
     * user id ของสมาชิกที่ควรได้รับ push เมื่อมีข้อความใหม่
     * = ลูกค้าที่จอง active + สตาฟที่ assign (ไม่รวม admin เพื่อกัน push รั่วทุกทริป)
     */
    public function pushRecipientIds(TripSchedule $schedule): Collection
    {
        $customerIds = Booking::where('schedule_id', $schedule->id)
            ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
            ->whereNotNull('user_id')
            ->pluck('user_id');

        // เพื่อนที่เข้าร่วมการจอง (companion) ก็ควรได้รับ push ของห้องนี้ด้วย
        $memberIds = BookingMember::where('status', BookingMember::STATUS_ACTIVE)
            ->whereNotNull('user_id')
            ->whereHas('booking', fn ($q) => $q
                ->where('schedule_id', $schedule->id)
                ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES))
            ->pluck('user_id');

        $staffIds = $schedule->staff()->pluck('users.id');

        return $customerIds->merge($memberIds)->merge($staffIds)->unique()->values();
    }

    /**
     * รายชื่อสมาชิกในห้องแชทของรอบนี้ = ลูกค้าที่จอง active + เพื่อนที่ถูกเชิญ
     * (companion) + สตาฟที่ assign แต่ละคนพร้อมบทบาทสำหรับแสดงป้ายกำกับ
     * (admin/operator เข้าถึงได้แต่ไม่นับเป็นสมาชิกประจำรอบ)
     *
     * @return Collection<int, array{user: User, role: string}>
     */
    public function members(TripSchedule $schedule): Collection
    {
        $customerIds = Booking::where('schedule_id', $schedule->id)
            ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
            ->whereNotNull('user_id')
            ->pluck('user_id');

        $companionIds = BookingMember::where('status', BookingMember::STATUS_ACTIVE)
            ->whereNotNull('user_id')
            ->whereHas('booking', fn ($q) => $q
                ->where('schedule_id', $schedule->id)
                ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES))
            ->pluck('user_id');

        $staffIds = $schedule->staff()->pluck('users.id');

        $allIds = $customerIds->merge($companionIds)->merge($staffIds)->unique()->values();

        if ($allIds->isEmpty()) {
            return collect();
        }

        $users = User::whereIn('id', $allIds)->get(['id', 'name', 'nickname', 'avatar', 'phone']);
        $staffSet = $staffIds->unique();

        // Staff badge wins when a user is both booked and assigned as staff.
        return $users->map(fn (User $u) => [
            'user' => $u,
            'role' => $staffSet->contains($u->id) ? 'staff' : 'customer',
        ])->values();
    }

    /**
     * id ของรอบเดินทางที่ผู้ใช้เป็นสมาชิกห้องแชท (ลูกค้าที่จอง active + เพื่อนที่
     * ถูกเชิญ + สตาฟที่ assign) ใช้สร้างรายการ "แชทของฉัน"
     *
     * @return Collection<int, int>
     */
    public function userScheduleIds(User $user): Collection
    {
        $bookingScheduleIds = Booking::where('user_id', $user->id)
            ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
            ->pluck('schedule_id');

        $companionScheduleIds = BookingMember::where('user_id', $user->id)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->whereHas('booking', fn ($q) => $q
                ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES))
            ->with('booking:id,schedule_id')
            ->get()
            ->pluck('booking.schedule_id');

        $staffScheduleIds = $user->assignedSchedules()->pluck('trip_schedules.id');

        return $bookingScheduleIds
            ->merge($companionScheduleIds)
            ->merge($staffScheduleIds)
            ->filter()
            ->unique()
            ->values();
    }

    public function markRead(User $user, TripSchedule $schedule, int $messageId): void
    {
        $read = ChatRead::firstOrNew([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
        ]);

        if ($messageId > (int) $read->last_read_message_id) {
            $read->last_read_message_id = $messageId;
            $read->save();
        }
    }

    public function unreadCount(User $user, TripSchedule $schedule): int
    {
        $lastRead = (int) ChatRead::where('schedule_id', $schedule->id)
            ->where('user_id', $user->id)
            ->value('last_read_message_id');

        return ChatMessage::where('schedule_id', $schedule->id)
            ->where('id', '>', $lastRead)
            ->when($user->exists, fn ($q) => $q->where(function ($w) use ($user) {
                $w->whereNull('user_id')->orWhere('user_id', '!=', $user->id);
            }))
            ->count();
    }

    /**
     * สิทธิ์ดูแลห้อง (ปักหมุด / ลบข้อความ) = สตาฟที่ assign หรือ admin/operator
     */
    public function canModerate(User $user, TripSchedule $schedule): bool
    {
        return $this->isAssignedStaff($user, $schedule)
            || $user->hasAnyRole(['admin', 'operator']);
    }

    /**
     * โพสต์ข้อความระบบเข้าห้อง (เช่น "คนขับออกเดินทางแล้ว") แล้วกระจายเรียลไทม์
     */
    public function postSystem(TripSchedule $schedule, string $body): ChatMessage
    {
        $message = ChatMessage::create([
            'schedule_id' => $schedule->id,
            'user_id' => null,
            'sender_role' => 'system',
            'body' => $body,
        ]);

        broadcast(new ChatMessageSent($message));

        return $message;
    }

    /**
     * โพสต์ข้อความต้อนรับครั้งแรกของห้อง — เรียกแบบ lazy ตอนเปิดห้องครั้งแรกที่
     * ยังไม่มีข้อความใดเลย เพื่อให้ทุกห้องเริ่มต้นด้วยการทักทาย idempotent โดย
     * เช็คว่ามีข้อความระบบประเภทต้อนรับอยู่แล้วหรือยัง
     */
    public function ensureWelcome(TripSchedule $schedule): void
    {
        $hasAny = ChatMessage::where('schedule_id', $schedule->id)->exists();
        if ($hasAny) {
            return;
        }

        $title = $schedule->trip?->title ?? 'ทริปนี้';
        $this->postSystem(
            $schedule,
            "ยินดีต้อนรับเข้าสู่ห้องแชท “{$title}” 🎉 ใช้ห้องนี้พูดคุยกับเพื่อนร่วมทริปและทีมงานได้เลย ทีมงานจะคอยดูแลและอัปเดตข่าวสารให้นะคะ 🌿",
        );
    }

    /**
     * แก้ไขเนื้อหาข้อความ (เฉพาะข้อความตัวอักษรของเจ้าของ) แล้วกระจายเรียลไทม์
     */
    public function editMessage(ChatMessage $message, string $body): ChatMessage
    {
        $message->forceFill([
            'body' => $body,
            'edited_at' => now(),
        ])->save();

        $message->load(['user', 'replyTo.user', 'reactions']);
        broadcast(new ChatMessageUpdated($message));

        return $message;
    }

    /**
     * ลบข้อความแบบ soft — เก็บแถวไว้เพื่อความต่อเนื่องของเธรด แต่ล้างเนื้อหา
     * ปลดหมุดถ้าถูกปักไว้ แล้วกระจายให้ทุกเครื่องอัปเดตเป็น "ข้อความถูกลบ"
     */
    public function deleteMessage(ChatMessage $message): void
    {
        if ($message->image_path) {
            Storage::disk(MediaDisk::name())->delete($message->image_path);
        }

        $message->forceFill([
            'is_deleted' => true,
            'body' => null,
            'image_path' => null,
            'mentions' => null,
            'pinned_at' => null,
            'pinned_by_id' => null,
        ])->save();

        $message->load(['user', 'replyTo.user', 'reactions']);
        broadcast(new ChatMessageUpdated($message));
    }

    /**
     * คัดเฉพาะ user_id ที่เป็นสมาชิกห้องนี้จริง จากรายการ mention ที่ client ส่งมา
     *
     * @param  array<int|string>  $ids
     * @return array<int, int>
     */
    public function sanitizeMentions(TripSchedule $schedule, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $memberIds = $this->pushRecipientIds($schedule)
            ->map(fn ($id) => (int) $id)
            ->all();

        return collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => in_array($id, $memberIds, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * ปักหมุดข้อความ — หนึ่งห้องมีหมุดเดียว ปักใหม่จะปลดอันเดิมอัตโนมัติ
     */
    public function pinMessage(User $user, ChatMessage $message): ChatMessage
    {
        ChatMessage::where('schedule_id', $message->schedule_id)
            ->whereNotNull('pinned_at')
            ->where('id', '!=', $message->id)
            ->update(['pinned_at' => null, 'pinned_by_id' => null]);

        $message->forceFill([
            'pinned_at' => now(),
            'pinned_by_id' => $user->id,
        ])->save();

        return $message;
    }

    public function unpinMessage(ChatMessage $message): void
    {
        $message->forceFill(['pinned_at' => null, 'pinned_by_id' => null])->save();
    }

    public function pinnedMessage(TripSchedule $schedule): ?ChatMessage
    {
        return ChatMessage::where('schedule_id', $schedule->id)
            ->whereNotNull('pinned_at')
            ->with(['user:id,name,nickname,avatar', 'user.loyaltyAccount:id,user_id,tier', 'pinnedBy:id,name,nickname'])
            ->latest('pinned_at')
            ->first();
    }

    /**
     * สลับรีแอกชันของผู้ใช้บนข้อความ (กดซ้ำ = เอาออก) คืนค่ารายการรีแอกชันรวม
     */
    public function toggleReaction(User $user, ChatMessage $message, string $emoji): array
    {
        if (! in_array($emoji, self::REACTION_EMOJIS, true)) {
            $emoji = self::REACTION_EMOJIS[0];
        }

        $existing = ChatReaction::where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            ChatReaction::create([
                'message_id' => $message->id,
                'user_id' => $user->id,
                'emoji' => $emoji,
            ]);
        }

        return $this->aggregateReactions($message->fresh('reactions'));
    }

    /**
     * รวมรีแอกชันของข้อความเป็น [{emoji, count, user_ids}] เรียงตามจำนวน
     *
     * @return array<int, array{emoji: string, count: int, user_ids: array<int>}>
     */
    public function aggregateReactions(ChatMessage $message): array
    {
        $reactions = $message->relationLoaded('reactions')
            ? $message->reactions
            : $message->reactions()->get();

        return $reactions
            ->groupBy('emoji')
            ->map(fn ($group, $emoji) => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'user_ids' => $group->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * แปลงข้อความเป็น payload สำหรับ API / broadcast (รวม reply, reactions, pin)
     */
    public function presentMessage(ChatMessage $message, ?int $currentUserId = null): array
    {
        $user = $message->user;
        $deleted = (bool) $message->is_deleted;

        return [
            'id' => $message->id,
            'schedule_id' => $message->schedule_id,
            'body' => $deleted ? null : $message->body,
            'image_url' => $deleted ? null : $message->image_url,
            'sender_role' => $message->sender_role,
            'is_mine' => $currentUserId !== null && $message->user_id === $currentUserId,
            'is_deleted' => $deleted,
            'edited_at' => $message->edited_at?->toISOString(),
            'mentions' => $deleted ? [] : array_map('intval', $message->mentions ?? []),
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'nickname' => $user->nickname,
                'avatar_url' => $user->avatar_url,
                ...$user->tierBadge(),
            ] : null,
            'reply_to' => $this->presentReplyExcerpt($message->replyTo),
            'reactions' => $this->aggregateReactions($message),
            'is_pinned' => $message->pinned_at !== null,
            'created_at' => $message->created_at?->toISOString(),
        ];
    }

    /**
     * ตัวอย่างข้อความที่ถูกตอบกลับ (แสดงในกล่อง quote)
     */
    public function presentReplyExcerpt(?ChatMessage $message): ?array
    {
        if (! $message) {
            return null;
        }

        $author = $message->user?->nickname ?: $message->user?->name;

        return [
            'id' => $message->id,
            'sender_name' => $message->sender_role === 'system' ? 'ระบบ' : ($author ?? 'ผู้ใช้'),
            'sender_role' => $message->sender_role,
            'body' => $message->body ? Str::limit($message->body, 120) : null,
            'has_image' => $message->image_path !== null,
        ];
    }

    /**
     * payload ของข้อความที่ปักหมุด สำหรับห้อง/broadcast (null = ไม่มีหมุด)
     */
    public function pinnedPayload(?ChatMessage $message): ?array
    {
        if (! $message) {
            return null;
        }

        $author = $message->user?->nickname ?: $message->user?->name;
        $pinnedBy = $message->pinnedBy?->nickname ?: $message->pinnedBy?->name;

        return [
            'id' => $message->id,
            'body' => $message->body,
            'image_url' => $message->image_url,
            'sender_role' => $message->sender_role,
            'sender_name' => $message->sender_role === 'system' ? 'ระบบ' : ($author ?? 'ผู้ใช้'),
            'pinned_by' => $pinnedBy,
            'created_at' => $message->created_at?->toISOString(),
        ];
    }

    private function isAssignedStaff(User $user, TripSchedule $schedule): bool
    {
        return $schedule->staff()->where('users.id', $user->id)->exists();
    }
}
