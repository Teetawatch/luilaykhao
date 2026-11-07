<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

/**
 * ข้อความใน response ผ่าน `__()` เสมอ — ข้อความไทยที่มีคำแปลใน lang/en.json
 * ออกเป็นภาษาอังกฤษเมื่อแอปขอ (ดู App\Support\AppLocale) ไม่ว่าจะถูก throw
 * มาจาก service ไหน ข้อความที่ยังไม่มีคำแปลออกเป็นไทยเหมือนเดิม
 */
trait ApiResponse
{
    protected function success($data = null, string $message = 'สำเร็จ', int $code = 200, array $meta = []): JsonResponse
    {
        $response = [
            'success' => true,
            'data' => $data,
            'message' => __($message),
        ];

        if (! empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $code);
    }

    protected function error(string $message = 'เกิดข้อผิดพลาด', int $code = 400, $errors = null): JsonResponse
    {
        $response = [
            'success' => false,
            'data' => null,
            'message' => __($message),
        ];

        if ($errors) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    /**
     * @param  array  $meta  ค่าเพิ่มเติมที่ผสมเข้ากับ meta ของ paginator (เช่นยอดรวม
     *                       แยกตามสถานะที่ต้องนับจากทั้งชุด ไม่ใช่แค่หน้าปัจจุบัน)
     */
    protected function paginated($paginator, string $message = 'สำเร็จ', array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'message' => __($message),
            'meta' => array_merge([
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ], $meta),
        ]);
    }
}
