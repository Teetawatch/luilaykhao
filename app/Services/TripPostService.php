<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripPost;
use App\Models\TripPostComment;
use App\Models\TripPostLike;
use App\Models\TripPostReport;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ฟีดรูปหลังทริป (community feed) — โพสต์สาธารณะต่อทริปจากลูกค้าที่เดินทางจริง
 * โพสต์ขึ้นฟีดทันที (ไม่ต้องรออนุมัติ) แอดมินซ่อน/ลบภายหลังได้
 * และซ่อนอัตโนมัติเมื่อถูก report ครบ AUTO_HIDE_REPORTS ครั้ง
 */
class TripPostService
{
    public const MAX_PHOTOS = 6;

    /**
     * โพสต์ได้เฉพาะคนที่เคยเดินทางทริปนี้จริง: มี booking confirmed
     * (เป็นเจ้าของ หรือสมาชิก active) บนรอบที่ออกเดินทางไปแล้ว
     */
    public function canPost(User $user, Trip $trip): bool
    {
        return Booking::where('status', 'confirmed')
            ->whereHas('schedule', function ($q) use ($trip) {
                $q->where('trip_id', $trip->id)
                    ->whereDate('departure_date', '<=', now('Asia/Bangkok')->toDateString());
            })
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhereHas('members', fn ($m) => $m
                        ->where('user_id', $user->id)
                        ->where('status', 'active'));
            })
            ->exists();
    }

    /**
     * สร้างโพสต์ใหม่ — เก็บรูปขึ้น storage (R2) แล้วขึ้นฟีดทันที
     *
     * @param  list<UploadedFile>  $images
     */
    public function create(User $user, Trip $trip, array $images, ?string $caption, ?int $scheduleId = null): TripPost
    {
        if (! $this->canPost($user, $trip)) {
            throw new \Exception('โพสต์ได้เฉพาะผู้ที่เคยเดินทางทริปนี้เท่านั้น');
        }

        if ($images === [] || count($images) > self::MAX_PHOTOS) {
            throw new \Exception('กรุณาแนบรูป 1-'.self::MAX_PHOTOS.' รูป');
        }

        $disk = MediaDisk::name();
        $photos = [];

        foreach ($images as $image) {
            $path = $image->store('trip-posts/'.date('Y/m'), $disk);
            [$width, $height] = $this->imageSize($image);

            $photos[] = [
                'disk' => $disk,
                'path' => $path,
                'url' => MediaDisk::url($path),
                'width' => $width,
                'height' => $height,
            ];
        }

        // ผูกกับรอบที่เดินทางล่าสุดของผู้โพสต์ ถ้าไม่ได้ระบุมา
        $scheduleId ??= $this->latestTraveledScheduleId($user, $trip);

        return TripPost::create([
            'trip_id' => $trip->id,
            'schedule_id' => $scheduleId,
            'user_id' => $user->id,
            'caption' => $caption !== null ? trim($caption) : null,
            'photos' => $photos,
            'status' => TripPost::STATUS_PUBLISHED,
        ]);
    }

    /**
     * กดไลก์/เลิกไลก์ — คืน true เมื่อผลลัพธ์คือ "ไลก์อยู่"
     * แจ้งเจ้าของโพสต์ผ่าน push เมื่อมีคนอื่นมากดไลก์
     */
    public function toggleLike(User $user, TripPost $post): bool
    {
        $existing = TripPostLike::where('post_id', $post->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            DB::transaction(function () use ($existing, $post) {
                $existing->delete();
                TripPost::whereKey($post->id)->where('likes_count', '>', 0)->decrement('likes_count');
            });

            return false;
        }

        DB::transaction(function () use ($user, $post) {
            TripPostLike::create(['post_id' => $post->id, 'user_id' => $user->id]);
            $post->increment('likes_count');
        });

        if ($post->user_id !== $user->id) {
            $this->notifyOwner(
                $post,
                'trip_post_liked',
                'มีคนถูกใจรูปของคุณ',
                ($user->nickname ?: $user->name).' ถูกใจโพสต์ของคุณในทริป '.$this->tripTitle($post),
            );
        }

        return true;
    }

    /**
     * คอมเมนต์ใต้โพสต์ + แจ้งเจ้าของโพสต์
     */
    public function addComment(User $user, TripPost $post, string $body): TripPostComment
    {
        $body = trim($body);

        if ($body === '') {
            throw new \Exception('กรุณาพิมพ์ข้อความคอมเมนต์');
        }

        $comment = DB::transaction(function () use ($user, $post, $body) {
            $comment = TripPostComment::create([
                'post_id' => $post->id,
                'user_id' => $user->id,
                'body' => $body,
            ]);
            $post->increment('comments_count');

            return $comment;
        });

        if ($post->user_id !== $user->id) {
            $this->notifyOwner(
                $post,
                'trip_post_comment',
                'มีคอมเมนต์ใหม่ในโพสต์ของคุณ',
                ($user->nickname ?: $user->name).': '.mb_strimwidth($body, 0, 80, '…'),
            );
        }

        return $comment;
    }

    /**
     * ลบคอมเมนต์ — เจ้าของคอมเมนต์ เจ้าของโพสต์ หรือแอดมิน
     */
    public function deleteComment(User $user, TripPost $post, int $commentId): void
    {
        $comment = TripPostComment::where('post_id', $post->id)->whereKey($commentId)->first();

        if (! $comment) {
            throw new \Exception('ไม่พบคอมเมนต์');
        }

        $allowed = $comment->user_id === $user->id
            || $post->user_id === $user->id
            || $user->hasAnyRole(['admin', 'operator']);

        if (! $allowed) {
            throw new \Exception('คุณไม่มีสิทธิ์ลบคอมเมนต์นี้');
        }

        DB::transaction(function () use ($comment, $post) {
            $comment->delete();
            TripPost::whereKey($post->id)->where('comments_count', '>', 0)->decrement('comments_count');
        });
    }

    /**
     * ผู้ใช้รายงานโพสต์ — แจ้งแอดมิน และซ่อนอัตโนมัติเมื่อครบเกณฑ์
     */
    public function report(User $user, TripPost $post, ?string $reason = null): void
    {
        $exists = TripPostReport::where('post_id', $post->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($exists) {
            throw new \Exception('คุณรายงานโพสต์นี้ไปแล้ว');
        }

        DB::transaction(function () use ($user, $post, $reason) {
            TripPostReport::create([
                'post_id' => $post->id,
                'user_id' => $user->id,
                'reason' => $reason !== null ? trim($reason) : null,
            ]);
            $post->increment('reports_count');
        });

        $post->refresh();

        if ($post->isPublished() && $post->reports_count >= TripPost::AUTO_HIDE_REPORTS) {
            $this->hide($post);
        }

        $this->notifyAdmins($post, $reason);
    }

    /**
     * แอดมินซ่อนโพสต์ (หรือระบบซ่อนอัตโนมัติจาก report)
     */
    public function hide(TripPost $post, ?int $adminId = null): void
    {
        $post->update([
            'status' => TripPost::STATUS_HIDDEN,
            'hidden_at' => now(),
            'hidden_by' => $adminId,
        ]);
    }

    public function unhide(TripPost $post): void
    {
        $post->update([
            'status' => TripPost::STATUS_PUBLISHED,
            'hidden_at' => null,
            'hidden_by' => null,
        ]);
    }

    /**
     * payload โพสต์สำหรับ API — ข้อมูลผู้โพสต์ รูป ยอดไลก์/คอมเมนต์
     * และสถานะของผู้ที่กำลังดู (ไลก์แล้วหรือยัง / ลบได้ไหม)
     */
    public function present(TripPost $post, ?int $viewerId = null): array
    {
        $user = $post->relationLoaded('user') ? $post->user : $post->user()->first();
        $trip = $post->relationLoaded('trip') ? $post->trip : $post->trip()->first();

        return [
            'id' => $post->id,
            'caption' => $post->caption,
            'photos' => collect($post->photos ?? [])->map(fn ($p) => [
                'url' => $p['url'] ?? null,
                'width' => $p['width'] ?? null,
                'height' => $p['height'] ?? null,
            ])->values()->all(),
            'likes_count' => $post->likes_count,
            'comments_count' => $post->comments_count,
            'status' => $post->status,
            'created_at' => $post->created_at?->toISOString(),
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->nickname ?: $user->name,
                'avatar_url' => $user->avatar_url,
            ] : null,
            'trip' => $trip ? [
                'id' => $trip->id,
                'slug' => $trip->slug,
                'title' => $trip->title,
            ] : null,
            'liked_by_me' => $viewerId !== null && $post->relationLoaded('likes')
                ? $post->likes->contains('user_id', $viewerId)
                : false,
            'is_mine' => $viewerId !== null && $post->user_id === $viewerId,
        ];
    }

    public function presentComment(TripPostComment $comment, ?int $viewerId = null, ?TripPost $post = null): array
    {
        $user = $comment->relationLoaded('user') ? $comment->user : $comment->user()->first();

        $canDelete = $viewerId !== null && (
            $comment->user_id === $viewerId
            || ($post !== null && $post->user_id === $viewerId)
        );

        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toISOString(),
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->nickname ?: $user->name,
                'avatar_url' => $user->avatar_url,
            ] : null,
            'can_delete' => $canDelete,
        ];
    }

    /**
     * รอบล่าสุดของทริปนี้ที่ผู้ใช้เดินทางไปแล้ว — ไว้ผูกโพสต์กับรอบเดินทาง
     */
    private function latestTraveledScheduleId(User $user, Trip $trip): ?int
    {
        $booking = Booking::where('status', 'confirmed')
            ->whereHas('schedule', function ($q) use ($trip) {
                $q->where('trip_id', $trip->id)
                    ->whereDate('departure_date', '<=', now('Asia/Bangkok')->toDateString());
            })
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhereHas('members', fn ($m) => $m
                        ->where('user_id', $user->id)
                        ->where('status', 'active'));
            })
            ->with('schedule:id,departure_date')
            ->get()
            ->sortByDesc(fn (Booking $b) => $b->schedule?->departure_date)
            ->first();

        return $booking?->schedule_id;
    }

    private function notifyOwner(TripPost $post, string $type, string $title, string $body): void
    {
        if (! $post->user_id) {
            return;
        }

        try {
            SmartNotification::send(
                $post->user_id,
                $type,
                $title,
                $body,
                [
                    'post_id' => $post->id,
                    'trip_slug' => $post->trip?->slug ?? $post->trip()->first()?->slug,
                    'route' => 'trip_post',
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('Trip post notification failed: '.$e->getMessage());
        }
    }

    private function notifyAdmins(TripPost $post, ?string $reason): void
    {
        $tripTitle = $this->tripTitle($post);
        $reasonText = $reason !== null && trim($reason) !== '' ? ' (เหตุผล: '.trim($reason).')' : '';

        try {
            User::role(['admin', 'operator'])->each(function (User $admin) use ($post, $tripTitle, $reasonText) {
                SmartNotification::send(
                    $admin->id,
                    'trip_post_reported',
                    'มีการรายงานโพสต์ในฟีดทริป',
                    "โพสต์ #{$post->id} ของทริป {$tripTitle} ถูกรายงาน{$reasonText} รวม {$post->reports_count} ครั้ง",
                    [
                        'post_id' => $post->id,
                        'route' => 'admin.trip_posts',
                    ],
                );
            });
        } catch (\Throwable $e) {
            Log::warning('Trip post admin report notification failed: '.$e->getMessage());
        }
    }

    private function tripTitle(TripPost $post): string
    {
        $trip = $post->relationLoaded('trip') ? $post->trip : $post->trip()->first();

        return $trip?->title ?? 'ทริป';
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function imageSize(UploadedFile $image): array
    {
        try {
            $size = getimagesize($image->getRealPath());

            return [$size[0] ?? null, $size[1] ?? null];
        } catch (\Throwable) {
            return [null, null];
        }
    }
}
