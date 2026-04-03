<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function success($data = null, string $message = 'สำเร็จ', int $code = 200, array $meta = []): JsonResponse
    {
        $response = [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $code);
    }

    protected function error(string $message = 'เกิดข้อผิดพลาด', int $code = 400, $errors = null): JsonResponse
    {
        $response = [
            'success' => false,
            'data' => null,
            'message' => $message,
        ];

        if ($errors) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    protected function paginated($paginator, string $message = 'สำเร็จ'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'message' => $message,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
