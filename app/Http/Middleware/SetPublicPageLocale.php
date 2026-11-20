<?php

namespace App\Http\Middleware;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\User;
use App\Support\AppLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * ภาษาของหน้าลิงก์ที่ส่งให้ลูกค้า (/t/ ใบเดินทาง, /p/ กรอกข้อมูล, /f/ บัตรเพื่อน,
 * /r/ ฟอร์มจอง, /claim/, /pay/) — หน้าพวกนี้เป็น Blade ไม่ใช่ SPA จึงไม่มี /en
 *
 * ลำดับ: ?lang= ที่กดสลับเอง (จำไว้ในคุกกี้) → คุกกี้ → ภาษาของเจ้าของลิงก์
 * (users.locale ที่เว็บ /en หรือแอปจำไว้) → ไทย ไม่เดาจากภาษาเบราว์เซอร์ —
 * คนไทยจำนวนมากตั้งเบราว์เซอร์เป็นอังกฤษ (ดู AppLocale)
 */
class SetPublicPageLocale
{
    public const COOKIE = 'llk_page_lang';

    public function handle(Request $request, Closure $next): Response
    {
        $chosen = AppLocale::normalise($request->query('lang')) ?? AppLocale::normalise($request->input('lang'));
        $locale = $chosen
            ?? AppLocale::normalise($request->cookie(self::COOKIE))
            ?? AppLocale::normalise($this->owner($request)?->locale)
            ?? AppLocale::THAI;

        app()->setLocale($locale);

        if ($chosen !== null) {
            Cookie::queue(self::COOKIE, $chosen, 60 * 24 * 365, null, null, null, false);
        }

        return $next($request);
    }

    /** เจ้าของลิงก์ — เลือกจากชนิดของลิงก์ (ส่วนแรกของ path) */
    private function owner(Request $request): ?User
    {
        $token = (string) $request->route('token');
        if ($token === '') {
            return null;
        }

        return match ($request->segment(1)) {
            't' => Booking::where('brief_token', $token)->first()?->user,
            'pay' => Booking::where('payment_token', strtolower(trim($token)))->first()?->user,
            'p' => BookingPassenger::where('self_fill_token', $token)->first()?->booking?->user,
            'f' => BookingPassenger::where('pass_token', $token)->first()?->booking?->user,
            'claim' => User::where('claim_token', $token)->first(),
            default => null,
        };
    }
}
