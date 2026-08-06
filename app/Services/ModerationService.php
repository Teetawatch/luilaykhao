<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ContentReport;
use App\Models\Review;
use App\Models\SmartNotification;
use App\Models\TripPost;
use App\Models\TripPostComment;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ศูนย์กลางการดูแลเนื้อหาที่ผู้ใช้สร้างเอง
 *
 * มีสามหน้าที่ที่ App Store Guideline 1.2 บังคับให้แอปที่มี UGC ต้องมี:
 *   1. รายงานเนื้อหาได้ทุกชิ้น  → report()
 *   2. บล็อกผู้ใช้ที่ก่อกวนได้   → block()
 *   3. เนื้อหาที่ถูกรายงานถึงเกณฑ์ต้องหายไปเองระหว่างรอคนตรวจ → AUTO_HIDE_REPORTS
 *
 * (ข้อที่สี่ "กรองเนื้อหาไม่เหมาะสมตั้งแต่ตอนโพสต์" อยู่ที่ ContentFilterService)
 */
class ModerationService
{
    /** รายงานกี่ครั้งถึงซ่อนอัตโนมัติระหว่างรอแอดมินตรวจ */
    public const AUTO_HIDE_REPORTS = 5;

    /**
     * ชนิดเนื้อหาที่รายงานได้ — คีย์นี้คือค่าที่แอปส่งมาและที่แอดมินเห็น
     *
     * @var array<string, class-string<Model>>
     */
    public const TYPES = [
        'chat_message' => ChatMessage::class,
        'review' => Review::class,
        'trip_post' => TripPost::class,
        'trip_post_comment' => TripPostComment::class,
        'user' => User::class,
    ];

    /**
     * เหตุผลที่เลือกได้ — แอปแสดงเป็นตัวเลือก ไม่ใช่ช่องพิมพ์เปล่า
     * เพราะรายงานที่จัดกลุ่มได้คือรายงานที่แอดมินไล่ดูไหว
     *
     * @var array<string, string>
     */
    public const REASONS = [
        'spam' => 'สแปมหรือโฆษณา',
        'harassment' => 'คุกคามหรือกลั่นแกล้ง',
        'hate' => 'ใช้ถ้อยคำสร้างความเกลียดชัง',
        'sexual' => 'เนื้อหาทางเพศ',
        'violence' => 'ความรุนแรงหรือสิ่งผิดกฎหมาย',
        'false_info' => 'ข้อมูลเท็จ',
        'other' => 'อื่น ๆ',
    ];

    /**
     * รับรายงานหนึ่งใบ แล้วซ่อนเนื้อหาเองถ้าถูกรายงานถึงเกณฑ์
     *
     * @throws \Exception ถ้าชนิดไม่รู้จัก รายงานซ้ำ หรือรายงานเนื้อหาตัวเอง
     */
    public function report(User $reporter, string $type, int $id, ?string $reason = null, ?string $note = null): ContentReport
    {
        $target = $this->find($type, $id);

        if ($target === null) {
            throw new \Exception('ไม่พบเนื้อหาที่ต้องการรายงาน');
        }

        $authorId = $this->authorId($type, $target);

        if ($authorId !== null && $authorId === $reporter->id) {
            throw new \Exception('รายงานเนื้อหาของตัวเองไม่ได้');
        }

        $duplicate = ContentReport::where('reporter_id', $reporter->id)
            ->where('reportable_type', $type)
            ->where('reportable_id', $id)
            ->exists();

        if ($duplicate) {
            throw new \Exception('คุณรายงานเนื้อหานี้ไปแล้ว ทีมงานกำลังตรวจสอบอยู่');
        }

        $report = DB::transaction(function () use ($reporter, $type, $id, $authorId, $reason, $note, $target) {
            $created = ContentReport::create([
                'reporter_id' => $reporter->id,
                'reportable_type' => $type,
                'reportable_id' => $id,
                'author_id' => $authorId,
                'reason' => array_key_exists((string) $reason, self::REASONS) ? $reason : 'other',
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ]);

            $this->incrementReportCounter($type, $target);

            return $created;
        });

        $this->autoHideIfNeeded($type, $this->find($type, $id));
        $this->notifyAdmins($report);

        return $report;
    }

    // ── บล็อกผู้ใช้ ──────────────────────────────────────────────

    /**
     * @throws \Exception ถ้าบล็อกตัวเองหรือบล็อกทีมงาน
     */
    public function block(User $user, int $targetId): void
    {
        if ($user->id === $targetId) {
            throw new \Exception('บล็อกตัวเองไม่ได้');
        }

        $target = User::find($targetId);

        if ($target === null) {
            throw new \Exception('ไม่พบผู้ใช้ที่ต้องการบล็อก');
        }

        // ทีมงานคือช่องทางที่ลูกค้าใช้ติดต่อระหว่างทริป ถ้าบล็อกได้จะพลาดเรื่องความปลอดภัย
        if ($target->hasAnyRole(['admin', 'operator', 'driver'])) {
            throw new \Exception('บล็อกทีมงานไม่ได้ หากมีปัญหากรุณาแจ้งทีมงานผ่านศูนย์ช่วยเหลือ');
        }

        UserBlock::firstOrCreate([
            'blocker_id' => $user->id,
            'blocked_id' => $targetId,
        ]);
    }

    public function unblock(User $user, int $targetId): void
    {
        UserBlock::where('blocker_id', $user->id)
            ->where('blocked_id', $targetId)
            ->delete();
    }

    /**
     * id ของคนที่ผู้ใช้คนนี้บล็อกไว้ (ทางเดียว) — ใช้แสดงรายการ "ผู้ใช้ที่ถูกบล็อก"
     *
     * @return array<int, int>
     */
    public function blockedIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return UserBlock::where('blocker_id', $user->id)->pluck('blocked_id')->all();
    }

    /**
     * id ของคนที่ต้อง "หายไป" จากสายตาผู้ใช้คนนี้ — ทั้งคนที่เขาบล็อก
     * และคนที่บล็อกเขา การซ่อนต้องเป็นสองทาง ไม่งั้นฝ่ายที่ถูกบล็อกจะยัง
     * ตอบโต้ข้อความที่อีกฝ่ายมองไม่เห็นได้ ซึ่งคือสิ่งที่การบล็อกควรหยุด
     *
     * @return array<int, int>
     */
    public function hiddenAuthorIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return UserBlock::where('blocker_id', $user->id)
            ->orWhere('blocked_id', $user->id)
            ->get(['blocker_id', 'blocked_id'])
            ->map(fn (UserBlock $b) => $b->blocker_id === $user->id ? $b->blocked_id : $b->blocker_id)
            ->unique()
            ->values()
            ->all();
    }

    public function hasBlockBetween(?User $user, ?int $otherId): bool
    {
        if ($user === null || $otherId === null) {
            return false;
        }

        return in_array($otherId, $this->hiddenAuthorIds($user), true);
    }

    // ── ซ่อน / เอากลับคืน (แอดมิน) ────────────────────────────────

    public function hide(string $type, int $id, ?int $adminId = null): void
    {
        $target = $this->find($type, $id);

        if ($target === null) {
            return;
        }

        match ($type) {
            'chat_message' => $target->forceFill(['hidden_at' => now()])->save(),
            'review' => $target->forceFill(['is_approved' => false])->save(),
            'trip_post' => app(TripPostService::class)->hide($target, $adminId),
            'trip_post_comment' => $target->delete(),
            // ผู้ใช้ไม่ถูก "ซ่อน" — การจัดการคนเป็นเรื่องที่แอดมินต้องทำนอกระบบ
            default => null,
        };
    }

    public function unhide(string $type, int $id): void
    {
        $target = $this->find($type, $id);

        if ($target === null) {
            return;
        }

        match ($type) {
            'chat_message' => $target->forceFill(['hidden_at' => null])->save(),
            'review' => $target->forceFill(['is_approved' => true])->save(),
            'trip_post' => app(TripPostService::class)->unhide($target),
            default => null,
        };
    }

    public function resolve(ContentReport $report, string $status, ?int $adminId): void
    {
        $report->update([
            'status' => $status,
            'resolved_by' => $adminId,
            'resolved_at' => now(),
        ]);

        // รายงานใบอื่นที่ชี้เนื้อหาชิ้นเดียวกันถือว่าจบไปพร้อมกัน
        ContentReport::open()
            ->where('reportable_type', $report->reportable_type)
            ->where('reportable_id', $report->reportable_id)
            ->update([
                'status' => $status,
                'resolved_by' => $adminId,
                'resolved_at' => now(),
            ]);
    }

    // ── ข้อมูลประกอบสำหรับหน้าแอดมิน ────────────────────────────

    /**
     * ข้อความ/รูป ของเนื้อหาที่ถูกรายงาน เพื่อให้แอดมินตัดสินได้โดยไม่ต้องไปเปิดหา
     *
     * @return array<string, mixed>
     */
    public function preview(ContentReport $report): array
    {
        $target = $this->find($report->reportable_type, $report->reportable_id);

        if ($target === null) {
            return ['exists' => false, 'excerpt' => 'เนื้อหาถูกลบไปแล้ว', 'hidden' => true];
        }

        return match ($report->reportable_type) {
            'chat_message' => [
                'exists' => true,
                'excerpt' => $target->is_deleted ? '(ข้อความถูกลบ)' : (string) $target->body,
                'image_url' => $target->image_url,
                'hidden' => $target->hidden_at !== null,
                'schedule_id' => $target->schedule_id,
            ],
            'review' => [
                'exists' => true,
                'excerpt' => (string) $target->comment,
                'rating' => $target->rating,
                'hidden' => ! $target->is_approved,
                'trip_id' => $target->trip_id,
            ],
            'trip_post' => [
                'exists' => true,
                'excerpt' => (string) $target->caption,
                'image_url' => collect($target->photos ?? [])->first()['url'] ?? null,
                'hidden' => ! $target->isPublished(),
                'trip_id' => $target->trip_id,
            ],
            'trip_post_comment' => [
                'exists' => true,
                'excerpt' => (string) $target->body,
                'hidden' => false,
            ],
            'user' => [
                'exists' => true,
                'excerpt' => (string) ($target->nickname ?: $target->name),
                'hidden' => false,
            ],
            default => ['exists' => true, 'excerpt' => '', 'hidden' => false],
        };
    }

    // ── ภายใน ───────────────────────────────────────────────────

    public function find(string $type, int $id): ?Model
    {
        $class = self::TYPES[$type] ?? null;

        return $class === null ? null : $class::find($id);
    }

    private function authorId(string $type, Model $target): ?int
    {
        return $type === 'user' ? (int) $target->id : ($target->user_id !== null ? (int) $target->user_id : null);
    }

    private function incrementReportCounter(string $type, Model $target): void
    {
        if (in_array($type, ['chat_message', 'review', 'trip_post'], true)) {
            $target->increment('reports_count');
        }
    }

    private function autoHideIfNeeded(string $type, ?Model $target): void
    {
        if ($target === null || ! isset($target->reports_count)) {
            return;
        }

        if ($target->reports_count < self::AUTO_HIDE_REPORTS) {
            return;
        }

        $alreadyHidden = match ($type) {
            'chat_message' => $target->hidden_at !== null,
            'review' => ! $target->is_approved,
            'trip_post' => ! $target->isPublished(),
            default => true,
        };

        if (! $alreadyHidden) {
            $this->hide($type, (int) $target->id);
        }
    }

    private function notifyAdmins(ContentReport $report): void
    {
        $label = match ($report->reportable_type) {
            'chat_message' => 'ข้อความในแชท',
            'review' => 'รีวิว',
            'trip_post' => 'โพสต์ในฟีด',
            'trip_post_comment' => 'คอมเมนต์ในฟีด',
            'user' => 'ผู้ใช้',
            default => 'เนื้อหา',
        };

        $reason = self::REASONS[$report->reason] ?? 'อื่น ๆ';

        try {
            User::role(['admin', 'operator'])->each(function (User $admin) use ($report, $label, $reason) {
                SmartNotification::send(
                    $admin->id,
                    'content_reported',
                    "มีการรายงาน{$label}",
                    "{$label} #{$report->reportable_id} ถูกรายงาน (เหตุผล: {$reason})",
                    [
                        'report_id' => $report->id,
                        'route' => 'admin.reports',
                    ],
                );
            });
        } catch (\Throwable $e) {
            Log::warning('Content report admin notification failed: '.$e->getMessage());
        }
    }
}
