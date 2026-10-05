<?php

namespace App\Services;

use App\Events\ChatLostItemUpdated;
use App\Events\ChatMessageSent;
use App\Jobs\PurgeEndedTripChatsJob;
use App\Jobs\SendChatPushJob;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\ChatMessage;
use App\Models\LostItem;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ของหาย / ลืมของ — หลังจบทริปมักเจอหมวก แว่น แบตสำรองค้างบนรถหรือที่พัก
 *
 * ทีมงานถ่ายรูปโพสต์ ลูกทริปทุกคนได้แจ้งเตือน เจ้าของกด "ของฉัน" พร้อมบอกว่าจะ
 * รับคืนยังไง แล้วทีมงานติ๊กว่าคืนแล้ว (ใส่เลขพัสดุได้)
 *
 * ห้องแชทถูกลบ 3 วันหลังจบทริป แต่ของหายมักเพิ่งเจอตอนนั้นพอดี — ของจึงอยู่ในตาราง
 * ของตัวเอง (รูปไม่ได้อยู่ใต้ chat/ ที่ถูกล้าง) การ์ดในแชทเป็นแค่ทางเข้าเพิ่มเติม
 * สิทธิ์ดูอิงคนที่ไปทริปจริง (รวมใบจองที่ completed แล้ว) ไม่ใช่สิทธิ์ห้องแชท
 */
class LostItemService
{
    public function __construct(
        private TripRosterService $roster,
        private ChatService $chatService,
        private FcmService $fcm,
    ) {}

    /** ทีมงานของรอบ (รวมคนที่ถูกปลดหลังจบทริปแล้ว — ของหายมักโผล่ตอนนั้น) หรือแอดมิน */
    public function canManage(User $user, TripSchedule $schedule): bool
    {
        return $user->hasAnyRole(['admin', 'operator'])
            || $schedule->staff()->where('users.id', $user->id)->exists();
    }

    public function canView(User $user, TripSchedule $schedule): bool
    {
        return $this->canManage($user, $schedule)
            || $this->roster->userIds($schedule)->contains((int) $user->id);
    }

    public function post(User $staff, TripSchedule $schedule, string $description, ?UploadedFile $photo): LostItem
    {
        $item = LostItem::create([
            'schedule_id' => $schedule->id,
            'posted_by_id' => $staff->id,
            'description' => $description,
            'photo_path' => $photo?->store('lost-items/'.date('Y/m'), MediaDisk::name()),
        ]);

        $tripTitle = $schedule->trip?->title ?? 'ทริปของคุณ';

        // ห้องแชทยังอยู่ = ลงการ์ดให้เห็นในแชทด้วย (เด้งแจ้งผ่าน SendChatPushJob)
        if ($this->chatIsOpen($schedule)) {
            $message = ChatMessage::create([
                'schedule_id' => $schedule->id,
                'user_id' => $staff->id,
                'sender_role' => $this->chatService->senderRole($staff, $schedule),
                'body' => "📦 ใครลืมของไว้? {$description} — ถ้าเป็นของคุณกด \"ของฉัน\" ในการ์ดนี้",
            ]);
            $item->update(['message_id' => $message->id]);
            $message->load(['user', 'replyTo.user', 'reactions', 'lostItem']);
            broadcast(new ChatMessageSent($message))->toOthers();
            $this->chatService->markRead($staff, $schedule, $message->id);
            SendChatPushJob::dispatch($message->id, $staff->id, [], true);

            return $item->fresh();
        }

        // ห้องแชทถูกลบไปแล้ว — แจ้งทุกคนที่ไปทริปตรง ๆ ให้เปิดหน้า "ของที่ลืมไว้"
        foreach ($this->roster->userIds($schedule) as $userId) {
            if ($userId === (int) $staff->id) {
                continue;
            }
            $this->send($userId, '📦 ใครลืมของไว้ในทริปนี้?', "{$description} · {$tripTitle} — ถ้าเป็นของคุณ กดเพื่อแจ้งทีมงาน", $item);
        }

        return $item;
    }

    public function claim(User $user, LostItem $item, ?string $note): LostItem
    {
        if ($item->returned_at) {
            throw new \Exception('ของชิ้นนี้คืนเจ้าของไปแล้ว');
        }
        if ($item->claimed_by_id && (int) $item->claimed_by_id !== (int) $user->id) {
            throw new \Exception('มีคนแจ้งว่าเป็นเจ้าของแล้ว ถ้าเป็นของคุณจริง ทักทีมงานได้เลยครับ');
        }

        $first = $item->claimed_by_id === null;
        $item->update([
            'claimed_by_id' => $user->id,
            'claimed_at' => $item->claimed_at ?? now(),
            'claim_note' => $note ?: null,
        ]);

        if ($first) {
            $who = $user->nickname ?: $user->name;
            foreach ($this->managerIds($item) as $staffId) {
                $this->send($staffId, '📦 มีเจ้าของแล้ว', "{$who} แจ้งว่า \"{$item->description}\" เป็นของตัวเอง"
                    .($note ? " — {$note}" : ''), $item);
            }
        }

        return $this->changed($item);
    }

    public function unclaim(LostItem $item): LostItem
    {
        if ($item->returned_at) {
            throw new \Exception('ของชิ้นนี้คืนเจ้าของไปแล้ว');
        }

        $item->update(['claimed_by_id' => null, 'claimed_at' => null, 'claim_note' => null]);

        return $this->changed($item);
    }

    public function markReturned(LostItem $item, bool $returned, ?string $note): LostItem
    {
        if ($returned && ! $item->claimed_by_id) {
            throw new \Exception('ยังไม่มีใครแจ้งว่าเป็นเจ้าของ');
        }

        $wasReturned = $item->returned_at !== null;
        $item->update($returned
            ? ['returned_at' => $item->returned_at ?? now(), 'returned_note' => $note ?: null]
            : ['returned_at' => null, 'returned_note' => null]);

        if ($returned && ! $wasReturned && $item->claimed_by_id) {
            $this->send((int) $item->claimed_by_id, '📦 ส่งของคืนแล้ว', "\"{$item->description}\"".($note ? " — {$note}" : ''), $item);
        }

        return $this->changed($item);
    }

    public function delete(LostItem $item): void
    {
        if ($item->photo_path) {
            try {
                Storage::disk(MediaDisk::name())->delete($item->photo_path);
            } catch (\Throwable $e) {
                Log::warning('LostItemService: ลบรูปไม่สำเร็จ', ['item_id' => $item->id, 'error' => $e->getMessage()]);
            }
        }

        $message = $item->message_id ? ChatMessage::find($item->message_id) : null;
        $item->delete();

        // การ์ดในแชทไม่มีของให้ชี้แล้ว — ลบข้อความตามไปด้วย
        if ($message) {
            $this->chatService->deleteMessage($message);
        }
    }

    /**
     * ของทั้งหมดในรอบ (ทีมงาน) หรือในทุกทริปที่ฉันไป (ลูกทริป — ย้อนหลัง 90 วัน)
     *
     * @return Collection<int, LostItem>
     */
    public function forUser(User $user, ?TripSchedule $schedule = null): Collection
    {
        if ($schedule) {
            return LostItem::where('schedule_id', $schedule->id)
                ->with(['schedule.trip', 'claimedBy', 'postedBy'])
                ->latest('id')
                ->get();
        }

        // ทริปที่ฉันไป (เจ้าของ/เพื่อนร่วมใบจอง รวมที่จบแล้ว) + รอบที่ฉันเป็นสตาฟ
        $bookingScheduleIds = Booking::query()
            ->whereIn('status', TripRosterService::STATUSES)
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->orWhereIn('id', BookingMember::where('user_id', $user->id)
                    ->where('status', BookingMember::STATUS_ACTIVE)
                    ->select('booking_id')))
            ->pluck('schedule_id');
        $staffScheduleIds = TripSchedule::whereHas('staff', fn ($q) => $q->where('users.id', $user->id))->pluck('id');

        return LostItem::whereIn('schedule_id', $bookingScheduleIds->merge($staffScheduleIds)->unique())
            ->where('created_at', '>=', now()->subDays(90))
            ->with(['schedule.trip', 'claimedBy', 'postedBy'])
            ->latest('id')
            ->get();
    }

    /**
     * payload — [viewer] null = ส่วนสาธารณะ (การ์ดที่กระจายทั้งห้อง): ไม่มีชื่อ/เบอร์/
     * วิธีรับคืนของเจ้าของ เห็นแค่ว่ามีเจ้าของแล้วหรือยัง
     *
     * @return array<string, mixed>
     */
    public function present(LostItem $item, ?User $viewer = null, ?bool $manager = null): array
    {
        $item->loadMissing(['schedule.trip', 'claimedBy', 'postedBy']);
        $manager ??= $viewer ? $this->canManage($viewer, $item->schedule) : false;
        $isClaimant = $viewer && (int) $item->claimed_by_id === (int) $viewer->id;
        $private = $manager || $isClaimant;

        return [
            'id' => $item->id,
            'schedule_id' => (int) $item->schedule_id,
            'message_id' => $item->message_id ? (int) $item->message_id : null,
            'trip_title' => $item->schedule?->trip?->title,
            'departure_date' => $item->schedule?->departure_date?->toDateString(),
            'description' => $item->description,
            'photo_url' => $item->photo_url,
            'posted_by_name' => $item->postedBy?->nickname ?: $item->postedBy?->name,
            'status' => $item->status(),
            // ผู้ดูคนนี้จัดการของชิ้นนี้ได้ไหม (แอปใช้สลับปุ่มทีมงาน/ลูกทริป)
            'can_manage' => $manager,
            'claimed_by_id' => $item->claimed_by_id ? (int) $item->claimed_by_id : null,
            'is_mine' => $isClaimant,
            'claimed_at' => $item->claimed_at?->toISOString(),
            'returned_at' => $item->returned_at?->toISOString(),
            'created_at' => $item->created_at?->toISOString(),
            ...($private ? [
                'claim_note' => $item->claim_note,
                'returned_note' => $item->returned_note,
            ] : []),
            ...($manager ? [
                'claimant_name' => $item->claimedBy ? ($item->claimedBy->nickname ?: $item->claimedBy->name) : null,
                'claimant_phone' => $item->claimedBy?->phone,
            ] : []),
        ];
    }

    /** ห้องแชทของรอบยังไม่ถูกล้าง (ล้าง 3 วันหลังวันกลับ — PurgeEndedTripChatsJob) */
    public function chatIsOpen(TripSchedule $schedule): bool
    {
        $end = $schedule->return_date ?? $schedule->departure_date;
        if (! $end) {
            return true;
        }
        $cutoff = now(PurgeEndedTripChatsJob::TIMEZONE)->subDays(PurgeEndedTripChatsJob::DELETE_AFTER_DAYS)->toDateString();

        return $end->toDateString() > $cutoff;
    }

    /** @return Collection<int, int> */
    private function managerIds(LostItem $item): Collection
    {
        // คนโพสต์ก่อน (อาจเป็นสตาฟที่ถูกปลดจากรอบแล้ว) แล้วตามด้วยสตาฟที่ยังดูแลรอบอยู่
        return collect([$item->posted_by_id])
            ->merge($item->schedule->activeStaff()->pluck('users.id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function changed(LostItem $item): LostItem
    {
        $fresh = $item->fresh(['schedule.trip', 'claimedBy', 'postedBy']);

        if ($fresh->message_id) {
            broadcast(new ChatLostItemUpdated((int) $fresh->schedule_id, (int) $fresh->message_id, $this->present($fresh)));
        }

        return $fresh;
    }

    private function send(int $userId, string $title, string $body, LostItem $item): void
    {
        try {
            $this->fcm->sendToUser($userId, $title, $body, [
                'type' => 'lost_item',
                'route' => 'lost_items',
                'schedule_id' => (string) $item->schedule_id,
                'lost_item_id' => (string) $item->id,
            ], ['android_channel' => SendChatPushJob::ANDROID_CHANNEL]);
        } catch (\Throwable $e) {
            Log::warning('LostItemService: ส่ง push ไม่สำเร็จ', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
