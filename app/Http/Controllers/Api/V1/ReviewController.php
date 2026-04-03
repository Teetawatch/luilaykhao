<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Review;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReviewController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = Review::with(['user', 'trip', 'repliedBy'])
            ->where('is_approved', true);

        if ($request->filled('trip_id')) {
            $query->where('trip_id', $request->trip_id);
        }

        if ($request->filled('rating')) {
            $query->where('rating', $request->rating);
        }

        $reviews = $query->orderByDesc('created_at')->paginate($request->get('per_page', 10));

        return $this->paginated($reviews->through(fn($r) => $this->formatReview($r)));
    }

    public function myReviews(Request $request): JsonResponse
    {
        $reviews = Review::with(['trip', 'booking'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($r) => $this->formatReview($r));

        return $this->success($reviews);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_id' => ['required', 'exists:bookings,id'],
            'rating'     => ['required', 'integer', 'min:1', 'max:5'],
            'comment'    => ['nullable', 'string', 'max:2000'],
            'images'     => ['nullable', 'array', 'max:5'],
            'images.*'   => ['string'],
        ]);

        $booking = Booking::where('id', $validated['booking_id'])
            ->where('user_id', $request->user()->id)
            ->where('status', 'confirmed')
            ->firstOrFail();

        $existing = Review::where('booking_id', $booking->id)->first();
        if ($existing) {
            return $this->error('คุณรีวิวการจองนี้ไปแล้ว', 422);
        }

        $review = Review::create([
            'user_id'    => $request->user()->id,
            'booking_id' => $booking->id,
            'trip_id'    => $booking->schedule->trip_id,
            'rating'     => $validated['rating'],
            'comment'    => $validated['comment'] ?? null,
            'images'     => $validated['images'] ?? [],
        ]);

        return $this->success($this->formatReview($review->load(['user', 'trip'])), 'รีวิวสำเร็จแล้ว', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $review = Review::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $validated = $request->validate([
            'rating'  => ['sometimes', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'images'  => ['nullable', 'array', 'max:5'],
            'images.*' => ['string'],
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

        $path = $request->file('image')->store('reviews', 'public');
        $url = Storage::url($path);

        return $this->success(['url' => $url], 'อัปโหลดรูปภาพสำเร็จ');
    }

    private function formatReview(Review $r): array
    {
        return [
            'id'               => $r->id,
            'user_name'        => $r->user?->name ?? 'ไม่ระบุชื่อ',
            'user_id'          => $r->user_id,
            'trip_id'          => $r->trip_id,
            'trip_title'       => $r->trip?->title ?? '-',
            'booking_id'       => $r->booking_id,
            'rating'           => $r->rating,
            'comment'          => $r->comment,
            'images'           => $r->images ?? [],
            'admin_reply'      => $r->admin_reply,
            'admin_replied_by' => $r->repliedBy?->name,
            'admin_replied_at' => $r->admin_replied_at?->toISOString(),
            'is_approved'      => $r->is_approved,
            'created_at'       => $r->created_at?->toISOString(),
        ];
    }
}
