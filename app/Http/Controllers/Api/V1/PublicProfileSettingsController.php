<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * ตั้งค่าโปรไฟล์สาธารณะของตัวเอง — เปิด/ปิด และเขียนคำแนะนำตัว
 *
 * handle ถูกจองครั้งแรกที่เรียก endpoint นี้แล้วคงที่ตลอด ผู้ใช้จึงแชร์ลิงก์ได้
 * โดยไม่ต้องกลัวว่าลิงก์เก่าจะตายเมื่อเปลี่ยนชื่อเล่นทีหลัง
 */
class PublicProfileSettingsController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        return $this->success($this->payload($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'bio' => ['nullable', 'string', 'max:160'],
        ], [], [
            'enabled' => 'การเปิดโปรไฟล์สาธารณะ',
            'bio' => 'คำแนะนำตัว',
        ]);

        $user = $request->user();

        $user->ensurePublicHandle();
        $user->fill([
            'public_profile_enabled' => $data['enabled'],
            'public_bio' => $data['bio'] ?? null,
        ])->save();

        // สถิติในการ์ด OG แคชไว้ 6 ชม. — ล้างทิ้งเมื่อเจ้าตัวเพิ่งแก้ ไม่งั้นลิงก์
        // ที่แชร์ทันทีหลังแก้จะยังโชว์การ์ดเวอร์ชันเก่า
        Cache::forget('profile-og:'.$user->public_handle);

        return $this->success($this->payload($user), 'บันทึกการตั้งค่าโปรไฟล์สาธารณะแล้ว');
    }

    private function payload($user): array
    {
        return [
            'enabled' => (bool) $user->public_profile_enabled,
            'handle' => $user->public_handle,
            'bio' => $user->public_bio,
            'url' => $user->public_handle ? url('/u/'.$user->public_handle) : null,
        ];
    }
}
