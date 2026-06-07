<?php

use App\Http\Controllers\AdminPaymentWebController;
use App\Http\Controllers\Api\V1\PublicAlbumController;
use App\Http\Controllers\PublicPaymentController;
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
    ])->header('Content-Type', 'text/xml');
});

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

// SPA catch-all (must be last!)
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
