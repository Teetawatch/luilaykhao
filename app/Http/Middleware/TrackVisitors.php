<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackVisitors
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            \App\Models\Visitor::updateOrCreate(
                ['ip_address' => $request->ip(), 'visited_on' => \Carbon\Carbon::today()->toDateString()],
                ['last_seen_at' => \Carbon\Carbon::now()]
            );
        } catch (\Exception $e) {
            // Silently fail if DB has issues to not block user access
            \Illuminate\Support\Facades\Log::error('Visitor tracking failed: ' . $e->getMessage());
        }

        return $next($request);
    }
}
