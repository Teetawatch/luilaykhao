<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CoinTransaction;
use App\Services\CoinService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * กระเป๋า Coin ของฉัน — ยอดคงเหลือ กติกา และประวัติล่าสุด
 */
class CoinController extends Controller
{
    use ApiResponse;

    public function __construct(private CoinService $coins) {}

    public function show(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $transactions = CoinTransaction::where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (CoinTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'coins' => $t->coins,
                'balance_after' => $t->balance_after,
                'description' => $t->description,
                'created_at' => $t->created_at?->toISOString(),
            ]);

        return $this->success([
            'balance' => $this->coins->balance($userId),
            'rules' => $this->coins->rules(),
            'transactions' => $transactions,
        ]);
    }
}
