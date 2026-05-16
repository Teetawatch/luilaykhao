<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role'       => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->booted(function (): void {
        // Auth endpoints — ป้องกัน brute-force
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Payment charge — ป้องกัน duplicate submission
        RateLimiter::for('payment', function (Request $request) {
            $key = ($request->user()?->id ?? $request->ip());
            return Limit::perMinute(5)->by($key);
        });

        // Seat lock — ป้องกัน lock spam
        RateLimiter::for('seat-lock', function (Request $request) {
            $key = ($request->user()?->id ?? $request->ip());
            return Limit::perMinute(20)->by($key);
        });

        // Promotion validate — ป้องกัน code guessing
        RateLimiter::for('promotion', function (Request $request) {
            $key = ($request->user()?->id ?? $request->ip());
            return Limit::perMinute(10)->by($key);
        });

        // Contact form — ป้องกัน spam
        RateLimiter::for('contact', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        // General API — fallback สำหรับ public endpoints
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(120)->by($request->user()->id)
                : Limit::perMinute(60)->by($request->ip());
        });
    })
    ->create();
