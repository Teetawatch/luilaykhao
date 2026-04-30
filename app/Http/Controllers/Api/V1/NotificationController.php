<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FcmToken;
use App\Models\SmartNotification;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $notifications = SmartNotification::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 20));

        return $this->paginated($notifications->through(fn($n) => [
            'id'         => $n->id,
            'type'       => $n->type,
            'title'      => $n->title,
            'body'       => $n->body,
            'data'       => $n->data,
            'is_read'    => $n->is_read,
            'read_at'    => $n->read_at?->toISOString(),
            'created_at' => $n->created_at?->toISOString(),
        ]));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = SmartNotification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->count();

        return $this->success(['count' => $count]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = SmartNotification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $notification->update(['is_read' => true, 'read_at' => now()]);

        return $this->success(null, 'อ่านแล้ว');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        SmartNotification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return $this->success(null, 'อ่านทั้งหมดแล้ว');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        SmartNotification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail()
            ->delete();

        return $this->success(null, 'ลบการแจ้งเตือนสำเร็จ');
    }

    public function storePushToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', 'max:32'],
            'device_id' => ['nullable', 'string', 'max:128'],
        ]);

        FcmToken::updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'] ?? null,
                'device_id' => $validated['device_id'] ?? null,
                'is_active' => true,
                'last_used_at' => now(),
            ],
        );

        return $this->success(null, 'บันทึกอุปกรณ์รับแจ้งเตือนสำเร็จ');
    }

    public function destroyPushToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        FcmToken::where('user_id', $request->user()->id)
            ->where('token', $validated['token'])
            ->update(['is_active' => false]);

        return $this->success(null, 'ยกเลิกอุปกรณ์รับแจ้งเตือนสำเร็จ');
    }
}
