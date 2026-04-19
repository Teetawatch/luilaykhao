<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function getVisitorStats()
    {
        $today = \Carbon\Carbon::today()->toDateString();
        $fifteenMinutesAgo = \Carbon\Carbon::now()->subMinutes(15);

        return response()->json([
            'success' => true,
            'data' => [
                'today' => \App\Models\Visitor::where('visited_on', $today)->count(),
                'total' => \App\Models\Visitor::distinct('ip_address')->count('ip_address'),
                'online' => \App\Models\Visitor::where('last_seen_at', '>=', $fifteenMinutesAgo)->count(),
            ]
        ]);
    }
}
