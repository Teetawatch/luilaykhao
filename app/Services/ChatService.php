<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\ChatMessage;
use App\Models\ChatRead;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Collection;

class ChatService
{
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

    private function isAssignedStaff(User $user, TripSchedule $schedule): bool
    {
        return $schedule->staff()->where('users.id', $user->id)->exists();
    }
}
