<?php

namespace App\Services;

use App\Events\ChatMessageSent;
use App\Events\ChatMessageUpdated;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\ChatMessage;
use App\Models\ChatReaction;
use App\Models\ChatRead;
use App\Models\ChatRoomPreference;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatService
{
    /** ข้อความระบบที่มีปุ่มชวนให้กด — แอปวาดปุ่มตาม cta */
    public const CTA_BY_SYSTEM_KEY = [
        'intro_3d' => 'intro',
        'contacts_end' => 'contacts',
    ];

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

        $users = User::with('loyaltyAccount:id,user_id,tier')
            ->whereIn('id', $allIds)
            ->get(['id', 'name', 'nickname', 'avatar', 'phone']);
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

    /** ระดับการแจ้งเตือนของผู้ใช้คนนี้ในห้องนี้ (ไม่มีแถว = ทุกข้อความ) */
    public function notifyLevel(User $user, TripSchedule $schedule): string
    {
        return $this->notifyLevels($schedule->id, [$user->id])[$user->id]
            ?? ChatRoomPreference::LEVEL_ALL;
    }

    /**
     * ระดับการแจ้งเตือนของหลายคนในห้องเดียว — คืนเฉพาะคนที่เปลี่ยนจากค่าเริ่มต้น
     *
     * @param  iterable<int>  $userIds
     * @return array<int, string> user_id => notify_level
     */
    public function notifyLevels(int $scheduleId, iterable $userIds): array
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return ChatRoomPreference::where('schedule_id', $scheduleId)
            ->whereIn('user_id', $ids)
            ->pluck('notify_level', 'user_id')
            ->mapWithKeys(fn ($level, $userId) => [(int) $userId => (string) $level])
            ->all();
    }

    /**
     * ระดับที่มีผลจริงตอนนี้ — "เงียบจนใกล้วันเดินทาง" กลายเป็น "ทุกข้อความ"
     * ตั้งแต่ UNTIL_TRIP_DAYS วันก่อนเดินทาง (นับตามวันที่ไทย)
     */
    public function effectiveLevel(string $level, TripSchedule $schedule): string
    {
        if ($level !== ChatRoomPreference::LEVEL_UNTIL_TRIP) {
            return $level;
        }

        return $this->untilTripActive($schedule)
            ? ChatRoomPreference::LEVEL_IMPORTANT
            : ChatRoomPreference::LEVEL_ALL;
    }

    /** ยังอยู่ในช่วง "เงียบไว้ก่อน" ไหม (ยังเหลือมากกว่า UNTIL_TRIP_DAYS วัน) */
    public function untilTripActive(TripSchedule $schedule): bool
    {
        $departure = $schedule->departure_date?->toDateString();
        if ($departure === null) {
            return false;
        }

        $wakeOn = Carbon::parse($departure, 'Asia/Bangkok')
            ->subDays(ChatRoomPreference::UNTIL_TRIP_DAYS)
            ->toDateString();

        return now('Asia/Bangkok')->toDateString() < $wakeOn;
    }

    /** วันที่ (Y-m-d) ที่ "เงียบไว้ก่อน" จะกลับมาแจ้งทุกข้อความ */
    public function untilTripWakesOn(TripSchedule $schedule): ?string
    {
        $departure = $schedule->departure_date?->toDateString();

        return $departure === null ? null : Carbon::parse($departure, 'Asia/Bangkok')
            ->subDays(ChatRoomPreference::UNTIL_TRIP_DAYS)
            ->toDateString();
    }

    /** ตั้งระดับการแจ้งเตือน — กลับไป "ทุกข้อความ" = ลบแถวทิ้ง */
    public function setNotifyLevel(User $user, TripSchedule $schedule, string $level): void
    {
        $match = ['schedule_id' => $schedule->id, 'user_id' => $user->id];

        if ($level === ChatRoomPreference::LEVEL_ALL) {
            ChatRoomPreference::where($match)->delete();

            return;
        }

        ChatRoomPreference::updateOrCreate($match, ['notify_level' => $level]);
    }

    /**
     * ข้อความที่ผู้ใช้คนนี้ควรเห็น — ตัดข้อความที่ถูกซ่อนจากการรายงาน
     * และข้อความของคนที่บล็อกกันไว้ออก
     *
     * ใช้ผ่าน ->tap() ทุกที่ที่ดึงข้อความในห้อง เพื่อให้เงื่อนไขอยู่ที่เดียว
     * ไม่ต้องไปจำว่าเส้นทางไหนกรองแล้วบ้าง
     *
     * @param  Builder<ChatMessage>  $query
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        $query->whereNull('hidden_at');

        $blocked = app(ModerationService::class)->hiddenAuthorIds($user);

        if ($blocked !== []) {
            $query->where(function ($q) use ($blocked) {
                // ข้อความระบบไม่มีเจ้าของ (user_id เป็น null) ต้องไม่หายไปด้วย
                $q->whereNull('user_id')->orWhereNotIn('user_id', $blocked);
            });
        }

        return $query;
    }

    public function unreadCount(User $user, TripSchedule $schedule): int
    {
        $lastRead = (int) ChatRead::where('schedule_id', $schedule->id)
            ->where('user_id', $user->id)
            ->value('last_read_message_id');

        return ChatMessage::where('schedule_id', $schedule->id)
            ->tap(fn ($q) => $this->scopeVisibleTo($q, $user))
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
     *
     * $systemKey = คีย์ของข้อความอัตโนมัติตามไทม์ไลน์ (unique ต่อห้อง) ปล่อยว่าง
     * ได้สำหรับข้อความระบบทั่วไปที่โพสต์ซ้ำได้
     */
    public function postSystem(
        TripSchedule $schedule,
        string $body,
        ?string $systemKey = null,
        ?string $imagePath = null,
    ): ChatMessage {
        $message = ChatMessage::create([
            'schedule_id' => $schedule->id,
            'user_id' => null,
            'sender_role' => 'system',
            'system_key' => $systemKey,
            'body' => $body,
            'image_path' => $imagePath,
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
            "ยินดีต้อนรับเข้าสู่ห้องแชท “{$title}” 🎉 ใช้ห้องนี้พูดคุยกับเพื่อนร่วมทริปและทีมงานได้เลย ทีมงานจะคอยดูแลและอัปเดตข่าวสารให้นะครับ 🌿",
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
        foreach ([$message->image_path, $message->audio_path] as $path) {
            if ($path) {
                Storage::disk(MediaDisk::name())->delete($path);
            }
        }

        $message->forceFill([
            'is_deleted' => true,
            'body' => null,
            'image_path' => null,
            'audio_path' => null,
            'audio_duration_ms' => null,
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

    /** ปักหมุดได้พร้อมกันกี่ข้อความต่อห้อง — เกินแล้วอันที่ปักนานสุดหลุดเอง */
    public const MAX_PINS = 3;

    /**
     * ปักหมุดข้อความ — ห้องหนึ่งปักได้ MAX_PINS อัน ปักเพิ่มเกินแล้วอันเก่าสุดหลุด
     * (แอป/เว็บรุ่นเก่าที่รู้จักหมุดเดียวยังเห็นอันล่าสุดผ่าน pinned_message)
     */
    public function pinMessage(User $user, ChatMessage $message): ChatMessage
    {
        $message->forceFill([
            'pinned_at' => now(),
            'pinned_by_id' => $user->id,
        ])->save();

        $keep = ChatMessage::where('schedule_id', $message->schedule_id)
            ->whereNotNull('pinned_at')
            ->orderByDesc('pinned_at')
            ->orderByDesc('id')
            ->limit(self::MAX_PINS)
            ->pluck('id');

        ChatMessage::where('schedule_id', $message->schedule_id)
            ->whereNotNull('pinned_at')
            ->whereNotIn('id', $keep)
            ->update(['pinned_at' => null, 'pinned_by_id' => null]);

        return $message;
    }

    public function unpinMessage(ChatMessage $message): void
    {
        $message->forceFill(['pinned_at' => null, 'pinned_by_id' => null])->save();
    }

    public function pinnedMessage(TripSchedule $schedule): ?ChatMessage
    {
        return $this->pinnedMessages($schedule)->first();
    }

    /**
     * หมุดทั้งหมดของห้อง ปักล่าสุดก่อน
     *
     * @return Collection<int, ChatMessage>
     */
    public function pinnedMessages(TripSchedule $schedule): Collection
    {
        return ChatMessage::where('schedule_id', $schedule->id)
            ->whereNull('hidden_at')
            ->where('is_deleted', false)
            ->whereNotNull('pinned_at')
            ->with(['user:id,name,nickname,avatar', 'user.loyaltyAccount:id,user_id,tier', 'pinnedBy:id,name,nickname'])
            ->orderByDesc('pinned_at')
            ->orderByDesc('id')
            ->limit(self::MAX_PINS)
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pinnedPayloads(TripSchedule $schedule): array
    {
        return $this->pinnedMessages($schedule)
            ->map(fn (ChatMessage $m) => $this->pinnedPayload($m))
            ->values()
            ->all();
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
            'audio_url' => $deleted ? null : $message->audio_url,
            'audio_duration_ms' => $deleted ? null : $message->audio_duration_ms,
            // สติกเกอร์ — body ยังเป็นข้อความสำรอง "[สติกเกอร์] …" สำหรับไคลเอนต์ที่ไม่รู้จัก
            'sticker' => app(StickerService::class)->forMessage($message),
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
            'poll' => $this->presentPoll($message, $currentUserId),
            'food_round' => $this->presentFoodRound($message),
            'rest_stop' => $this->presentRestStop($message),
            'collection' => $this->presentCollection($message),
            'lost_item' => $this->presentLostItem($message),
            'trail_check' => $this->presentCard($message, 'trailCheck', fn ($c) => app(ChatTrailService::class)->present($c, viewerId: $currentUserId)),
            'dropoff' => $this->presentCard($message, 'dropoffRound', fn ($c) => app(ChatDropoffService::class)->present($c, viewerId: $currentUserId)),
            'wellness' => $this->presentCard($message, 'wellnessCheck', fn ($c) => app(ChatWellnessService::class)->present($c, viewerId: $currentUserId)),
            'porter' => $this->presentCard($message, 'porterRound', fn ($c) => app(ChatPorterService::class)->present($c, viewerId: $currentUserId)),
            'staff_intro' => app(StaffIntroService::class)->forMessage($message),
            // การ์ดแนะนำตัวของลูกทริป
            'intro' => app(ChatIntroService::class)->forMessage($message),
            // ปุ่มชวนบนข้อความระบบบางตัว (แนะนำตัว D-3 / แลกคอนแทคตอนจบทริป)
            'cta' => self::CTA_BY_SYSTEM_KEY[$message->system_key] ?? null,
            // "รับทราบ" ใต้ประกาศ — null = ไม่ได้ขอ
            'ack' => app(ChatAckService::class)->present($message, $currentUserId),
            'is_pinned' => $message->pinned_at !== null,
            'created_at' => $message->created_at?->toISOString(),
        ];
    }

    /**
     * โพลที่แนบกับข้อความ (ถ้ามี) — ข้อความที่ถูกลบไม่ต้องส่งโพลไปด้วย
     *
     * @return array<string, mixed>|null
     */
    private function presentPoll(ChatMessage $message, ?int $currentUserId): ?array
    {
        if ($message->is_deleted) {
            return null;
        }

        $poll = $message->relationLoaded('poll') ? $message->poll : $message->poll()->first();

        return $poll ? app(ChatPollService::class)->present($poll, $currentUserId) : null;
    }

    /**
     * รอบรับออเดอร์อาหารที่แนบกับข้อความ (ถ้ามี)
     *
     * @return array<string, mixed>|null
     */
    private function presentFoodRound(ChatMessage $message): ?array
    {
        if ($message->is_deleted) {
            return null;
        }

        $round = $message->relationLoaded('foodRound') ? $message->foodRound : $message->foodRound()->first();

        return $round ? app(ChatFoodOrderService::class)->present($round) : null;
    }

    /**
     * จุดพัก (นัดเวลากลับรถ + เช็คชื่อ) ที่แนบกับข้อความ (ถ้ามี)
     *
     * @return array<string, mixed>|null
     */
    private function presentRestStop(ChatMessage $message): ?array
    {
        if ($message->is_deleted) {
            return null;
        }

        $stop = $message->relationLoaded('restStop') ? $message->restStop : $message->restStop()->first();

        return $stop ? app(ChatRestStopService::class)->present($stop) : null;
    }

    /**
     * ของหายที่ประกาศผ่านข้อความ — ส่วนสาธารณะเท่านั้น (ไม่มีชื่อ/เบอร์เจ้าของ)
     *
     * @return array<string, mixed>|null
     */
    private function presentLostItem(ChatMessage $message): ?array
    {
        if ($message->is_deleted) {
            return null;
        }

        $item = $message->relationLoaded('lostItem') ? $message->lostItem : $message->lostItem()->first();

        return $item ? app(LostItemService::class)->present($item) : null;
    }

    /**
     * การเก็บเงินหน้างานที่แนบกับข้อความ (ถ้ามี)
     *
     * @return array<string, mixed>|null
     */
    private function presentCollection(ChatMessage $message): ?array
    {
        if ($message->is_deleted) {
            return null;
        }

        $collection = $message->relationLoaded('collection') ? $message->collection : $message->collection()->first();

        return $collection ? app(ChatCollectionService::class)->present($collection) : null;
    }

    /**
     * การ์ดเครื่องมือหน้างานที่แนบกับข้อความ (ถ้ามี) — ข้อความที่ถูกลบไม่ส่งการ์ด
     * [currentUserId] ของข้อความที่ broadcast เป็น null: การ์ดที่มีส่วน "ของฉัน"
     * (เช็คอาการ จุดลง) จึงไม่มีส่วนนั้นใน broadcast แอปคงค่าเดิมไว้
     *
     * @param  callable(mixed): array<string, mixed>  $present
     * @return array<string, mixed>|null
     */
    private function presentCard(ChatMessage $message, string $relation, callable $present): ?array
    {
        if ($message->is_deleted) {
            return null;
        }

        $card = $message->relationLoaded($relation) ? $message->{$relation} : $message->{$relation}()->first();

        return $card ? $present($card) : null;
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
            'has_audio' => $message->audio_path !== null,
            'has_sticker' => $message->sticker_id !== null,
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
            // ข้อความเสียงไม่มีตัวหนังสือ — แถบหมุดโชว์ป้ายแทนแถบว่าง
            'body' => $message->body ?? ($message->audio_path ? ChatMessage::audioPreview($message->audio_duration_ms) : null),
            'image_url' => $message->image_url,
            'has_audio' => $message->audio_path !== null,
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
