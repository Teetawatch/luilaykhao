<?php

use App\Http\Middleware\SetAppLocale;
use App\Http\Middleware\TrackApiClient;
use App\Support\AppLocale;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ภาษาของข้อความที่ API สร้าง — ตาม X-App-Language ที่แอปส่ง (AppLocale)
        $middleware->api(append: [SetAppLocale::class, TrackApiClient::class]);

        // อยู่หลัง CDN/load balancer เมื่อไหร่ ต้องบอก IP ของพร็อกซีไว้ใน TRUSTED_PROXIES
        // (คั่นด้วย ,) ไม่อย่างนั้น request()->ip() จะเป็น IP ของพร็อกซี และ rate limit
        // ที่นับตาม IP จะรวมลูกค้าทุกคนเป็นถังเดียว — ตอนนี้ nginx คุยกับ PHP-FPM ตรง
        // จึงปล่อยว่างได้
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies === ['*'] ? '*' : $proxies);
        }

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report unhandled exceptions to Sentry (no-op when SENTRY_LARAVEL_DSN is unset).
        Integration::handles($exceptions);

        // ข้อความ validation ของ FormRequest เขียนเป็นไทย — แอปภาษาอังกฤษได้คำแปล
        // จาก lang/en.json (ไม่มีคำแปล = ไทยเหมือนเดิม) ภาษาไทยปล่อยให้ Laravel
        // render ตามเดิมทุกตัวอักษร
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->expectsJson() || app()->getLocale() === AppLocale::THAI) {
                return null;
            }

            $errors = array_map(
                fn (array $messages) => array_map(fn ($message) => __($message), $messages),
                $e->errors()
            );

            return response()->json([
                'message' => collect($errors)->flatten()->first() ?? __('The given data was invalid.'),
                'errors' => $errors,
            ], $e->status);
        });
    })
    ->booted(function (): void {
        // Auth endpoints — ป้องกัน brute-force
        // นับตาม IP + บัญชีที่พยายามเข้า: คนที่ใช้เน็ตเดียวกัน (ออฟฟิศ, เน็ตมือถือที่แชร์
        // IP) ไม่ล็อกกันเอง แต่การเดารหัสของบัญชีเดียวยังโดนที่ 10 ครั้ง/นาที และเพดาน
        // ต่อ IP กันการไล่ลองหลายบัญชีจากที่เดียว
        RateLimiter::for('auth', function (Request $request) {
            $ip = (string) $request->ip();
            $account = mb_strtolower(trim((string) ($request->input('email') ?? $request->input('login') ?? $request->input('phone') ?? '')));

            return [
                Limit::perMinute(10)->by('auth:'.$ip.'|'.$account),
                Limit::perMinute(60)->by('auth-ip:'.$ip),
            ];
        });

        // Payment charge — ป้องกัน duplicate submission
        RateLimiter::for('payment', function (Request $request) {
            $key = ($request->user()?->id ?? $request->ip());

            return Limit::perMinute(5)->by($key);
        });

        // ออก QR / หน้าชำระเงินของ Beam — แยกจาก 'payment' (5/นาที) เพราะแค่ออกใบแจ้ง
        // ยอด ไม่มีเงินไหลไหน แต่แอปออกใบใหม่ทุกครั้งที่ลูกค้าสลับเต็ม/มัดจำ/ผ่อน/จำนวน
        // งวด สลับสามสี่ทีแล้วกดจ่ายด้วยบัตรเลยโดน "Too Many Attempts" ที่นี่กันแค่
        // การยิงถล่ม Beam
        RateLimiter::for('beam-charge', function (Request $request) {
            $key = ($request->user()?->id ?? $request->ip());

            return Limit::perMinute(30)->by($key);
        });

        // Slip OCR scan — อ่านวันที่/เวลาจากสลิป (ลูกค้าอาจเปลี่ยนรูปหลายครั้ง)
        RateLimiter::for('slip-scan', function (Request $request) {
            $key = ($request->user()?->id ?? $request->ip());

            return Limit::perMinute(15)->by($key);
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

        // Resend verification — ทุกครั้งคืออีเมลจริงที่ส่งออกไป กันกดรัวจนกล่อง
        // จดหมายของลูกค้าเต็ม (คิดตามผู้ใช้ ไม่ใช่ IP เพราะออฟฟิศใช้ IP ร่วมกัน)
        RateLimiter::for('email-verify', function (Request $request) {
            return Limit::perMinute(3)->by($request->user()?->id ?? $request->ip());
        });

        // Chat — ป้องกันสแปมข้อความ
        RateLimiter::for('chat', function (Request $request) {
            $key = ($request->user()?->id ?? $request->ip());

            return Limit::perMinute(30)->by($key);
        });

        // AI ผู้ช่วยวางทริป — ทุกคำถามเสียเงินค่า API จริง จึงคุมให้แน่นกว่าปกติ
        RateLimiter::for('concierge', function (Request $request) {
            $key = ($request->user()?->id ?? $request->ip());

            return Limit::perMinute(6)->by($key);
        });

        // แปลข้อความแชท — ทุกครั้งที่ยังไม่มีในแคชคือค่า API จริง กดอ่านทีละข้อความ
        // ไล่ห้องได้สบาย ๆ แต่กันสคริปต์/กดรัวไม่ให้ไล่แปลทั้งห้องทุกภาษา
        RateLimiter::for('chat-translate', function (Request $request) {
            $key = 'chat-translate:'.($request->user()?->id ?? $request->ip());

            return [
                Limit::perMinute(20)->by($key.':m'),
                Limit::perDay(300)->by($key.':d'),
            ];
        });

        // General API — fallback สำหรับ public endpoints
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(120)->by($request->user()->id)
                : Limit::perMinute(60)->by($request->ip());
        });
    })
    ->create();
