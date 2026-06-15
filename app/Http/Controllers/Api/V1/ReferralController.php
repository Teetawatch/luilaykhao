<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReferralService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    use ApiResponse;

    public function __construct(private ReferralService $referralService) {}

    /**
     * The current user's referral snapshot: code, share copy, reward amounts,
     * totals and the list of invited friends.
     */
    public function show(Request $request): JsonResponse
    {
        return $this->success($this->referralService->stats($request->user()));
    }
}
