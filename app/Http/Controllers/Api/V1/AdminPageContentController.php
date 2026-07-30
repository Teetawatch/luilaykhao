<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\PageContent;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * แก้เนื้อหาหน้า "ข้อมูลก่อนไป" — schema ถูกส่งไปให้หน้าแอดมินวาดฟอร์มเอง
 * จึงไม่ต้องแก้ฝั่ง SPA ทุกครั้งที่เพิ่มช่องใหม่
 */
class AdminPageContentController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        return $this->success(PageContent::summaries());
    }

    public function show(string $key): JsonResponse
    {
        if (! PageContent::has($key)) {
            return $this->error('ไม่พบหน้าเนื้อหานี้', 404);
        }

        $definition = PageContent::definition($key);

        return $this->success([
            'key' => $key,
            'label' => $definition['label'],
            'route' => $definition['route'],
            'description' => $definition['description'],
            'fields' => $definition['fields'],
            'content' => PageContent::get($key),
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        if (! PageContent::has($key)) {
            return $this->error('ไม่พบหน้าเนื้อหานี้', 404);
        }

        $validated = $request->validate(PageContent::rules($key));

        PageContent::put($key, $validated['content']);

        return $this->success(PageContent::get($key), 'บันทึกเนื้อหาแล้ว');
    }

    public function reset(string $key): JsonResponse
    {
        if (! PageContent::has($key)) {
            return $this->error('ไม่พบหน้าเนื้อหานี้', 404);
        }

        PageContent::reset($key);

        return $this->success(PageContent::get($key), 'คืนค่าเนื้อหาเริ่มต้นแล้ว');
    }
}
