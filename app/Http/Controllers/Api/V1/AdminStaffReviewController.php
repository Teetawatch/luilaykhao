<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StaffReview;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * คะแนนรีวิวทีมงานจากลูกค้า (มุมมองผู้จัดการ)
 *
 * ลูกค้าให้ดาวสตาฟรายทริปมาตั้งแต่ต้น และสตาฟเห็นคะแนนของตัวเองได้ผ่าน
 * [StaffController::myReviews] — แต่ไม่มีใครเห็นภาพรวมทั้งทีม หน้านี้จึงสรุป
 * คะแนนเฉลี่ยรายคน (เรียงจากสูงสุด) พร้อมคอมเมนต์ล่าสุด เพื่อใช้ตอนจัดสตาฟ
 * ลงรอบและตอนรีวิวผลงาน
 */
class AdminStaffReviewController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $staffId = $request->integer('staff_user_id') ?: null;
        $days = $request->integer('days') ?: null;

        $scoped = fn ($query) => $query
            ->when($days, fn ($q) => $q->where('created_at', '>=', now()->subDays($days)))
            ->when($staffId, fn ($q) => $q->where('staff_user_id', $staffId));

        // สรุปรายคน — คิวรีเดียวแล้วค่อยผูกชื่อ กัน N+1
        $summary = $scoped(StaffReview::query())
            ->selectRaw('staff_user_id, COUNT(*) as total, AVG(rating) as avg_rating, SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) as low_ratings')
            ->groupBy('staff_user_id')
            ->get();

        $staffNames = User::whereIn('id', $summary->pluck('staff_user_id'))
            ->pluck('name', 'id');

        $leaderboard = $summary
            ->map(fn ($row) => [
                'staff_user_id' => (int) $row->staff_user_id,
                'staff_name' => $staffNames[$row->staff_user_id] ?? 'ทีมงาน',
                'total' => (int) $row->total,
                'avg_rating' => round((float) $row->avg_rating, 2),
                'low_ratings' => (int) $row->low_ratings,
            ])
            ->sortByDesc('avg_rating')
            ->values();

        $reviews = $scoped(StaffReview::with(['reviewer', 'staff', 'schedule.trip']))
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (StaffReview $r) => [
                'id' => $r->id,
                'rating' => $r->rating,
                'comment' => $r->comment,
                'staff_name' => $r->staff?->name,
                'staff_user_id' => $r->staff_user_id,
                'reviewer_name' => $r->reviewer?->name,
                'trip_title' => $r->schedule?->trip?->title,
                'departure_date' => $r->schedule?->departure_date?->toDateString(),
                'created_at' => $r->created_at?->toISOString(),
            ]);

        $allReviews = $scoped(StaffReview::query());

        return $this->success([
            'leaderboard' => $leaderboard,
            'reviews' => $reviews->values(),
            'overall' => [
                'total' => (clone $allReviews)->count(),
                'avg_rating' => round((float) (clone $allReviews)->avg('rating'), 2),
                'staff_count' => $leaderboard->count(),
            ],
        ]);
    }
}
