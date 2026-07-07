<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\TripPost;
use App\Services\TripPostService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ฟีดรูปหลังทริป — อ่านได้สาธารณะ (ทั้งฟีดรวมและฟีดต่อทริป)
 * โพสต์/ไลก์/คอมเมนต์/รายงาน ต้องล็อกอิน และโพสต์ได้เฉพาะคนที่เคยเดินทาง
 */
class TripPostController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TripPostService $posts,
    ) {}

    /**
     * ฟีดรวมทุกทริป (ใหม่สุดก่อน) — สาธารณะ
     */
    public function index(Request $request): JsonResponse
    {
        return $this->feed($request, null);
    }

    /**
     * ฟีดของทริปเดียว — สาธารณะ + แนบ can_post เมื่อผู้ใช้ล็อกอินมา
     */
    public function tripIndex(Request $request, string $slug): JsonResponse
    {
        $trip = Trip::where('slug', $slug)->firstOrFail();

        return $this->feed($request, $trip);
    }

    /**
     * โพสต์รูปเข้าฟีดของทริป (multipart: images[] 1-6 รูป + caption)
     */
    public function store(Request $request, string $slug): JsonResponse
    {
        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.TripPostService::MAX_PHOTOS],
            'images.*' => ['image', 'max:8192'],
            'caption' => ['nullable', 'string', 'max:1000'],
        ]);

        $trip = Trip::where('slug', $slug)->firstOrFail();

        try {
            $post = $this->posts->create(
                $request->user(),
                $trip,
                $request->file('images', []),
                $request->input('caption'),
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $post->load(['user:id,name,nickname,avatar', 'trip:id,slug,title']);

        return $this->success(
            $this->posts->present($post, $request->user()->id),
            'โพสต์รูปขึ้นฟีดแล้ว',
            201,
        );
    }

    /**
     * เจ้าของโพสต์ลบโพสต์ตัวเอง (แอดมินลบผ่านเส้นทาง admin)
     */
    public function destroy(Request $request, int $postId): JsonResponse
    {
        $post = TripPost::findOrFail($postId);

        if ($post->user_id !== $request->user()->id) {
            return $this->error('คุณไม่มีสิทธิ์ลบโพสต์นี้', 403);
        }

        $post->delete();

        return $this->success(null, 'ลบโพสต์แล้ว');
    }

    /**
     * กดไลก์/เลิกไลก์
     */
    public function like(Request $request, int $postId): JsonResponse
    {
        $post = TripPost::published()->findOrFail($postId);

        $liked = $this->posts->toggleLike($request->user(), $post);
        $post->refresh();

        return $this->success([
            'liked' => $liked,
            'likes_count' => $post->likes_count,
        ], $liked ? 'ถูกใจโพสต์แล้ว' : 'เลิกถูกใจแล้ว');
    }

    /**
     * รายการคอมเมนต์ของโพสต์ — สาธารณะ
     */
    public function comments(Request $request, int $postId): JsonResponse
    {
        $post = TripPost::published()->findOrFail($postId);
        $viewerId = auth('sanctum')->id();

        $comments = $post->comments()
            ->with('user:id,name,nickname,avatar')
            ->paginate(min((int) $request->input('per_page', 30), 50));

        $comments->getCollection()->transform(
            fn ($c) => $this->posts->presentComment($c, $viewerId, $post),
        );

        return $this->paginated($comments);
    }

    /**
     * คอมเมนต์ใต้โพสต์
     */
    public function storeComment(Request $request, int $postId): JsonResponse
    {
        $request->validate([
            'body' => ['required', 'string', 'max:500'],
        ]);

        $post = TripPost::published()->findOrFail($postId);

        try {
            $comment = $this->posts->addComment(
                $request->user(),
                $post,
                $request->input('body'),
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $comment->load('user:id,name,nickname,avatar');

        return $this->success(
            $this->posts->presentComment($comment, $request->user()->id, $post),
            'คอมเมนต์แล้ว',
            201,
        );
    }

    public function destroyComment(Request $request, int $postId, int $commentId): JsonResponse
    {
        $post = TripPost::findOrFail($postId);

        try {
            $this->posts->deleteComment($request->user(), $post, $commentId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(null, 'ลบคอมเมนต์แล้ว');
    }

    /**
     * รายงานโพสต์ไม่เหมาะสม
     */
    public function report(Request $request, int $postId): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $post = TripPost::findOrFail($postId);

        try {
            $this->posts->report($request->user(), $post, $request->input('reason'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(null, 'ขอบคุณสำหรับการรายงาน ทีมงานจะตรวจสอบโดยเร็ว');
    }

    // ── Admin (role:admin|operator ผ่าน route middleware) ────────

    /**
     * แอดมินดูโพสต์ทั้งหมด (รวมที่ถูกซ่อน) — กรอง status ได้
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $posts = TripPost::query()
            ->with(['user:id,name,nickname,avatar', 'trip:id,slug,title'])
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(min((int) $request->input('per_page', 20), 50));

        $posts->getCollection()->transform(function (TripPost $post) {
            $row = $this->posts->present($post);
            $row['reports_count'] = $post->reports_count;
            $row['hidden_at'] = $post->hidden_at?->toISOString();

            return $row;
        });

        return $this->paginated($posts);
    }

    public function adminHide(Request $request, int $postId): JsonResponse
    {
        $post = TripPost::findOrFail($postId);
        $this->posts->hide($post, $request->user()->id);

        return $this->success(null, 'ซ่อนโพสต์แล้ว');
    }

    public function adminUnhide(int $postId): JsonResponse
    {
        $post = TripPost::findOrFail($postId);
        $this->posts->unhide($post);

        return $this->success(null, 'แสดงโพสต์อีกครั้งแล้ว');
    }

    public function adminDestroy(int $postId): JsonResponse
    {
        TripPost::findOrFail($postId)->delete();

        return $this->success(null, 'ลบโพสต์แล้ว');
    }

    /**
     * ฟีดที่ published — แนบสถานะไลก์ของผู้ดู (ถ้าล็อกอินด้วย token)
     */
    private function feed(Request $request, ?Trip $trip): JsonResponse
    {
        // เส้นทางอ่านเป็นสาธารณะ แต่ถ้าแนบ Bearer token มาก็รู้ว่าใครดู
        $viewer = auth('sanctum')->user();

        $query = TripPost::published()
            ->with(['user:id,name,nickname,avatar', 'trip:id,slug,title'])
            ->when($trip, fn ($q) => $q->where('trip_id', $trip->id))
            ->latest('id');

        if ($viewer) {
            $query->with(['likes' => fn ($q) => $q->where('user_id', $viewer->id)]);
        }

        $posts = $query->paginate(min((int) $request->input('per_page', 15), 30));

        $posts->getCollection()->transform(
            fn (TripPost $post) => $this->posts->present($post, $viewer?->id),
        );

        $response = $this->paginated($posts);

        // ฟีดต่อทริป + มีผู้ใช้ล็อกอิน → บอกด้วยว่าโพสต์ได้ไหม (ไว้โชว์ปุ่มโพสต์)
        if ($trip && $viewer) {
            $data = $response->getData(true);
            $data['meta']['can_post'] = $this->posts->canPost($viewer, $trip);

            return response()->json($data);
        }

        return $response;
    }
}
