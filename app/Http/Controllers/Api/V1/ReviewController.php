<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Review;
use App\Support\MediaDisk;
use App\Support\ThaiDate;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReviewController extends Controller
{
    use ApiResponse;

    /** เพดานจำนวนรีวิวที่ดึงมาแตกเป็นรูป — flatMap ทำในหน่วยความจำ จึงต้องมีขอบ */
    private const PHOTO_REVIEW_LIMIT = 500;

    public function index(Request $request): JsonResponse
    {
        $query = Review::with(['user.loyaltyAccount', 'trip', 'repliedBy'])
            ->where('is_approved', true);

        if ($request->filled('trip_id')) {
            $query->where('trip_id', $request->trip_id);
        }

        if ($request->filled('rating')) {
            $query->where('rating', $request->rating);
        }

        $reviews = $query->orderByDesc('created_at')->paginate($request->get('per_page', 10));

        return $this->paginated($reviews->through(fn ($r) => $this->formatReview($r)));
    }

    /**
     * สรุปคะแนนรีวิวทั้งหมด (ค่าเฉลี่ยรวม, การกระจายดาว 1-5, ค่าเฉลี่ยรายหมวด)
     * ใช้ในหน้ารีวิวสาธารณะเพื่อแสดงภาพรวมความพึงพอใจ
     */
    public function stats(Request $request): JsonResponse
    {
        $base = Review::where('is_approved', true);

        if ($request->filled('trip_id')) {
            $base->where('trip_id', $request->trip_id);
        }

        $total = (clone $base)->count();

        $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        foreach ((clone $base)->selectRaw('rating, COUNT(*) as c')->groupBy('rating')->pluck('c', 'rating') as $rating => $count) {
            $distribution[(int) $rating] = (int) $count;
        }

        $average = $total > 0 ? round((clone $base)->avg('rating'), 2) : 0.0;

        $categories = [];
        foreach (['guide' => 'rating_guide', 'vehicle' => 'rating_vehicle', 'food' => 'rating_food', 'value' => 'rating_value'] as $key => $column) {
            $scoped = (clone $base)->whereNotNull($column);
            $count = $scoped->count();
            $categories[$key] = [
                'average' => $count > 0 ? round((clone $scoped)->avg($column), 2) : null,
                'count' => $count,
            ];
        }

        return $this->success([
            'total' => $total,
            'average' => (float) $average,
            'distribution' => $distribution,
            'with_media' => (clone $base)->where(fn ($q) => $q->whereNotNull('images')->orWhereNotNull('videos'))->count(),
            'with_reply' => (clone $base)->whereNotNull('admin_reply')->count(),
            'categories' => $categories,
        ]);
    }

    /**
     * อัลบั้มภาพจากผู้ร่วมทริป — รวมรูปจากทุกรีวิวที่อนุมัติแล้วของทริปนั้น
     * มาเรียงเป็นรายรูป (รีวิวใหม่สุดก่อน)
     */
    public function photos(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // ไม่บังคับ trip_id — ถ้าไม่ส่งมาคือดึงรูปจริงจากทุกทริป (กำแพงรูปหน้า /gallery)
            'trip_id' => ['nullable', 'integer', 'exists:trips,id'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $reviews = Review::with(['user.loyaltyAccount', 'trip:id,title,slug,location', 'booking.schedule:id,departure_date'])
            ->where('is_approved', true)
            ->whereNotNull('images')
            ->when(isset($validated['trip_id']), fn ($q) => $q->where('trip_id', $validated['trip_id']))
            ->orderByDesc('created_at')
            ->limit(self::PHOTO_REVIEW_LIMIT)
            ->get();

        // เดือนที่ "ไปจริง" คือวันออกเดินทางของรอบที่จอง ไม่ใช่วันที่เขียนรีวิว
        $travelDateOf = fn (Review $r) => $r->booking?->schedule?->departure_date ?? $r->created_at;

        if (isset($validated['month'])) {
            $reviews = $reviews->filter(fn (Review $r) => (int) $travelDateOf($r)?->month === (int) $validated['month']);
        }

        $photos = $reviews
            ->sortByDesc(fn (Review $r) => $travelDateOf($r))
            ->flatMap(function (Review $r) use ($travelDateOf) {
                $travelDate = $travelDateOf($r);

                return collect($r->images ?? [])->map(fn ($url) => [
                    'url' => $url,
                    'review_id' => $r->id,
                    'rating' => $r->rating,
                    'user_name' => $r->user?->name ?? 'ไม่ระบุชื่อ',
                    'user_avatar' => $r->user?->avatar_url,
                    'created_at' => $r->created_at?->toISOString(),
                    'trip_id' => $r->trip_id,
                    'trip_title' => $r->trip?->title,
                    'trip_slug' => $r->trip?->slug,
                    'location' => $r->trip?->location,
                    'travel_date' => $travelDate?->toDateString(),
                    'travel_month' => $travelDate ? (int) $travelDate->month : null,
                    'travel_month_label' => $travelDate ? ThaiDate::monthYear($travelDate) : null,
                ]);
            })
            ->values();

        $perPage = (int) ($validated['per_page'] ?? 24);
        $page = (int) ($validated['page'] ?? 1);

        return $this->success([
            'photos' => $photos->forPage($page, $perPage)->values(),
            'total' => $photos->count(),
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $photos->count() > $page * $perPage,
        ]);
    }

    public function myReviews(Request $request): JsonResponse
    {
        $reviews = Review::with(['trip', 'booking'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => $this->formatReview($r));

        return $this->success($reviews);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_id' => ['required', 'exists:bookings,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'rating_guide' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rating_vehicle' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rating_food' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rating_value' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'images' => ['nullable', 'array', 'max:6'],
            'images.*' => ['string'],
            'videos' => ['nullable', 'array', 'max:2'],
            'videos.*' => ['string'],
        ]);

        $userId = $request->user()->id;

        $booking = Booking::where('id', $validated['booking_id'])
            ->where('status', 'confirmed')
            ->with('schedule')
            ->firstOrFail();

        // เจ้าของการจอง หรือเพื่อนร่วมเดินทาง (companion) ที่รับคำเชิญแล้ว รีวิวได้คนละหนึ่งครั้ง
        if (! $booking->isAccessibleByUser($userId)) {
            return $this->error('คุณไม่มีสิทธิ์รีวิวการจองนี้', 403);
        }

        $existing = Review::where('booking_id', $booking->id)
            ->where('user_id', $userId)
            ->first();
        if ($existing) {
            return $this->error('คุณรีวิวการจองนี้ไปแล้ว', 422);
        }

        if (! $booking->schedule?->isReviewAvailable()) {
            return $this->error('สามารถรีวิวได้หลังจบทริปวันสุดท้าย เวลา 20:00 น. เป็นต้นไป', 422);
        }

        $review = Review::create([
            'user_id' => $userId,
            'booking_id' => $booking->id,
            'trip_id' => $booking->schedule->trip_id,
            'rating' => $validated['rating'],
            'rating_guide' => $validated['rating_guide'] ?? null,
            'rating_vehicle' => $validated['rating_vehicle'] ?? null,
            'rating_food' => $validated['rating_food'] ?? null,
            'rating_value' => $validated['rating_value'] ?? null,
            'comment' => $validated['comment'] ?? null,
            'images' => $validated['images'] ?? [],
            'videos' => $validated['videos'] ?? [],
        ]);

        return $this->success($this->formatReview($review->load(['user', 'trip'])), 'รีวิวสำเร็จแล้ว', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $review = Review::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $validated = $request->validate([
            'rating' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'rating_guide' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rating_vehicle' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rating_food' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rating_value' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'images' => ['nullable', 'array', 'max:6'],
            'images.*' => ['string'],
            'videos' => ['nullable', 'array', 'max:2'],
            'videos.*' => ['string'],
        ]);

        $review->update($validated);

        return $this->success($this->formatReview($review->fresh(['user', 'trip'])), 'อัปเดตรีวิวสำเร็จ');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $review = Review::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $review->delete();

        return $this->success(null, 'ลบรีวิวสำเร็จ');
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'max:5120'],
        ]);

        $path = $request->file('image')->store('reviews', MediaDisk::name());
        $url = MediaDisk::url($path);

        return $this->success(['url' => $url], 'อัปโหลดรูปภาพสำเร็จ');
    }

    public function uploadVideo(Request $request): JsonResponse
    {
        $request->validate([
            'video' => ['required', 'file', 'mimes:mp4,mov,quicktime,m4v', 'max:51200'], // max 50MB
        ]);

        $file = $request->file('video');
        $path = $file->storeAs(
            'reviews/videos',
            date('YmdHis').'_'.Str::random(10).'.'.strtolower($file->getClientOriginalExtension() ?: 'mp4'),
            MediaDisk::name(),
        );
        $url = MediaDisk::url($path);

        return $this->success(['url' => $url], 'อัปโหลดวิดีโอสำเร็จ');
    }

    private function formatReview(Review $r): array
    {
        return [
            'id' => $r->id,
            'user_name' => $r->user?->name ?? 'ไม่ระบุชื่อ',
            'user_avatar' => $r->user?->avatar_url,
            'user' => [
                'name' => $r->user?->name,
                'avatar_url' => $r->user?->avatar_url,
                ...($r->user?->tierBadge() ?? []),
            ],
            'user_id' => $r->user_id,
            'trip_id' => $r->trip_id,
            'trip_title' => $r->trip?->title ?? '-',
            'booking_id' => $r->booking_id,
            'rating' => $r->rating,
            'rating_guide' => $r->rating_guide,
            'rating_vehicle' => $r->rating_vehicle,
            'rating_food' => $r->rating_food,
            'rating_value' => $r->rating_value,
            'comment' => $r->comment,
            'images' => $r->images ?? [],
            'videos' => $r->videos ?? [],
            'admin_reply' => $r->admin_reply,
            'admin_replied_by' => $r->repliedBy?->name,
            'admin_replied_at' => $r->admin_replied_at?->toISOString(),
            'is_approved' => $r->is_approved,
            'created_at' => $r->created_at?->toISOString(),
        ];
    }
}
