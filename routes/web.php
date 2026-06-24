<?php

use App\Http\Controllers\AdminPaymentWebController;
use App\Http\Controllers\Api\V1\PublicAlbumController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\PublicBirthdateController;
use App\Http\Controllers\PublicPaymentController;
use App\Http\Controllers\SlipController;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Tag;
use App\Models\Trip;
use Illuminate\Support\Facades\Route;

// Google Search Console verification
Route::get('/google2a5171f00ca2654e.html', function () {
    return response('google-site-verification: google2a5171f00ca2654e.html')
        ->header('Content-Type', 'text/html');
});

// XML Sitemap
Route::get('/sitemap.xml', function () {
    $trips = Trip::where('status', 'published')->orWhere('status', 'active')->get();

    return response()->view('sitemap', [
        'trips' => $trips,
        'articles' => Article::published()->orderByDesc('published_at')->get(),
        'articleCategories' => ArticleCategory::whereHas('articles', fn ($q) => $q->published())->get(),
        'articleTags' => Tag::whereHas('articles', fn ($q) => $q->published())->get(),
    ])->header('Content-Type', 'text/xml');
});

// Blog (server-rendered for SEO — must be before the SPA catch-all). The
// category/tag archives are registered before the {slug} catch so they win.
Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/category/{slug}', [BlogController::class, 'category'])->name('blog.category');
Route::get('/blog/tag/{slug}', [BlogController::class, 'tag'])->name('blog.tag');
Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');

// Live Share Link — standalone tracking page (must be before the SPA catch-all)
Route::get('/track/{token}', function (string $token) {
    return response()->view('track', ['token' => $token]);
})->where('token', '[A-Za-z0-9]+');

// Driver Web Tracking — no-install GPS sender for drivers
Route::get('/driver/track', function () {
    return view('driver-track');
});

// Public photo album — ดาวน์โหลดรูปประจำรอบจากลิงก์สาธารณะ (ไม่ต้องล็อกอิน)
Route::get('/album/{token}', function (string $token) {
    return response()->view('album', ['token' => $token]);
})->where('token', '[A-Za-z0-9]+')->middleware('throttle:120,1');

Route::get('/album/{token}/download', [PublicAlbumController::class, 'downloadAll'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:30,1')
    ->name('album.download');

Route::get('/album/{token}/download/{photoId}', [PublicAlbumController::class, 'downloadOne'])
    ->where(['token' => '[A-Za-z0-9]+', 'photoId' => '[0-9]+'])
    ->middleware('throttle:120,1')
    ->name('album.download-one');

// Public birth-date page — ลูกค้ากรอกวัน/เดือน/ปีเกิดเองจากลิงก์เฉพาะคน (ไม่ต้องล็อกอิน)
Route::get('/birthdate/{token}', [PublicBirthdateController::class, 'show'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:30,1')
    ->name('public.birthdate.show');
Route::post('/birthdate/{token}', [PublicBirthdateController::class, 'submit'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:20,1')
    ->name('public.birthdate.submit');

// Per-booking variant — คนจองกรอกวันเกิดให้ผู้เดินทางทุกคนในการจอง (จองแทนเพื่อน)
Route::get('/booking-birthdate/{token}', [PublicBirthdateController::class, 'showBooking'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:30,1')
    ->name('public.birthdate.booking.show');
Route::post('/booking-birthdate/{token}', [PublicBirthdateController::class, 'submitBooking'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:20,1')
    ->name('public.birthdate.booking.submit');

// Public payment page — ชำระค่างวด/ยอดส่วนที่เหลือ จากลิงก์ในอีเมล (ไม่ต้องล็อกอิน)
Route::get('/pay/{token}', [PublicPaymentController::class, 'show'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:60,1')
    ->name('public.pay.show');
Route::post('/pay/{token}', [PublicPaymentController::class, 'pay'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:payment')
    ->name('public.pay.submit');

// Admin payment-tracking page — ดูลูกค้าที่ค้างชำระ + ส่งลิงก์ (session login, admin/operator)
Route::prefix('admin/payments')->group(function () {
    Route::get('/', [AdminPaymentWebController::class, 'index'])->name('admin.payments.index');
    Route::post('/login', [AdminPaymentWebController::class, 'login'])
        ->middleware('throttle:auth')->name('admin.payments.login');
    Route::post('/logout', [AdminPaymentWebController::class, 'logout'])->name('admin.payments.logout');
    Route::post('/{ref}/send-link', [AdminPaymentWebController::class, 'sendLink'])
        ->middleware('throttle:payment')->name('admin.payments.send-link');
});

// Signed slip viewer — streams a private payment slip; only the fallback for
// disks that can't mint presigned URLs (local dev). 'signed' enforces expiry.
Route::get('/slips/{token}', [SlipController::class, 'show'])
    ->middleware('signed')
    ->name('slips.show');

// SPA catch-all (must be last!)
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
