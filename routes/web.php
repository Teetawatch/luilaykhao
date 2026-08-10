<?php

use App\Http\Controllers\AdminPaymentWebController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\PublicAlbumController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\PaymentReturnController;
use App\Http\Controllers\PublicBirthdateController;
use App\Http\Controllers\PublicGiftController;
use App\Http\Controllers\PublicPassengerFillController;
use App\Http\Controllers\PublicPaymentController;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\PublicReceiptController;
use App\Http\Controllers\PublicSharePaymentController;
use App\Http\Controllers\SlipController;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\FaceSearchConsent;
use App\Models\Place;
use App\Models\Tag;
use App\Models\Trip;
use App\Support\MediaDisk;
use App\Support\SeoMeta;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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
        'places' => Place::published()->orderBy('name')->get(),
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

// Email verification link. Lives on the web side, not under /api, because it is
// clicked straight out of an inbox and has to answer with a redirect into the
// SPA rather than JSON. The signature is what authenticates it.
Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->where('id', '[0-9]+')
    ->middleware('throttle:auth')
    ->name('verification.verify');

// โปรไฟล์นักเดินทางสาธารณะ — server-rendered เพื่อให้บ็อตแชร์อ่าน OG meta ได้
// (ต้องมาก่อน SPA catch-all) การ์ด OG ลงท้าย .png จึงจดก่อนตัว {handle}
Route::get('/u/{handle}/og.png', [PublicProfileController::class, 'ogImage'])
    ->where('handle', '[a-z0-9]+')
    ->middleware('throttle:120,1')
    ->name('public.profile.og');
Route::get('/u/{handle}', [PublicProfileController::class, 'show'])
    ->where('handle', '[a-z0-9]+')
    ->middleware('throttle:120,1')
    ->name('public.profile.show');

// Live Share Link — standalone tracking page (must be before the SPA catch-all)
Route::get('/track/{token}', function (string $token) {
    return response()->view('track', ['token' => $token]);
})->where('token', '[A-Za-z0-9]+');

// Driver Web Tracking — no-install GPS sender for drivers
Route::get('/driver/track', function () {
    return view('driver-track');
});

// Digital Travel Receipt — หน้าตรวจสอบใบเสร็จสาธารณะจาก QR / ลิงก์ในอีเมล
Route::get('/receipt/{token}/pdf', [PublicReceiptController::class, 'pdf'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:120,1')
    ->name('public.receipt.pdf');
Route::get('/receipt/{token}', [PublicReceiptController::class, 'show'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:120,1')
    ->name('public.receipt.show');

// Gift reveal — หน้า "เปิดของขวัญ" สาธารณะจากลิงก์ที่ผู้ให้ส่งให้ผู้รับ
Route::get('/gift/{code}', [PublicGiftController::class, 'show'])
    ->where('code', '[A-Za-z0-9]+')
    ->middleware('throttle:120,1')
    ->name('public.gift.show');

// Universal Links (iOS) — ผูกโดเมนกับแอปเพื่อให้ https://luilaykhao.com/gift/*
// เปิดแอปโดยตรง ต้องเสิร์ฟที่ /.well-known/ เป็น application/json ผ่าน https ห้าม redirect
// คืน 404 เมื่อยังไม่ตั้งค่า IOS_APP_ID เพื่อไม่ให้ Apple แคชไฟล์ที่ผูกผิด
Route::get('/.well-known/apple-app-site-association', function () {
    $appId = config('app.ios_app_id');
    abort_if(blank($appId), 404);

    return response()->json([
        'applinks' => [
            'apps' => [],
            'details' => [[
                'appID' => $appId,
                // /reset-password: ลิงก์ตั้งรหัสผ่านใหม่ที่เมลไปหาลูกค้า เปิดใน
                // แอปได้เลยถ้ามีแอปติดอยู่ (ถ้าไม่มี ก็ตกไปที่หน้าเดียวกันใน SPA)
                'paths' => ['/gift/*', '/reset-password*'],
            ]],
        ],
    ]);
})->name('well-known.aasa');

// App Links (Android) — ผูก package + ลายนิ้วมือใบเซ็นแอปกับโดเมน
// คืน 404 เมื่อยังไม่ตั้งค่า ANDROID_CERT_FINGERPRINTS
Route::get('/.well-known/assetlinks.json', function () {
    $fingerprints = config('app.android_cert_fingerprints');
    abort_if(empty($fingerprints), 404);

    return response()->json([[
        'relation' => ['delegate_permission/common.handle_all_urls'],
        'target' => [
            'namespace' => 'android_app',
            'package_name' => config('app.android_package'),
            'sha256_cert_fingerprints' => array_values($fingerprints),
        ],
    ]]);
})->name('well-known.assetlinks');

// Public photo album — ดาวน์โหลดรูปประจำรอบจากลิงก์สาธารณะ (ไม่ต้องล็อกอิน)
Route::get('/album/{token}', function (string $token) {
    return response()->view('album', [
        'token' => $token,
        // เวอร์ชันข้อความขอความยินยอมค้นหาด้วยใบหน้า — ขยับเมื่อไหร่ ลูกค้าถูกถามใหม่
        'consentVersion' => FaceSearchConsent::CURRENT_VERSION,
    ]);
})->where('token', '[A-Za-z0-9]+')->middleware('throttle:120,1');

Route::get('/album/{token}/download', [PublicAlbumController::class, 'downloadAll'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:30,1')
    ->name('album.download');

Route::get('/album/{token}/download/{photoId}', [PublicAlbumController::class, 'downloadOne'])
    ->where(['token' => '[A-Za-z0-9]+', 'photoId' => '[0-9]+'])
    ->middleware('throttle:120,1')
    ->name('album.download-one');

// รูปแบบ inline จากโดเมนเดียวกัน — ใช้ตอนสแกนใบหน้าเมื่ออ่านรูปจาก R2 ตรง ๆ ไม่ได้
Route::get('/album/{token}/photo/{photoId}', [PublicAlbumController::class, 'photoFile'])
    ->where(['token' => '[A-Za-z0-9]+', 'photoId' => '[0-9]+'])
    ->middleware('throttle:600,1')
    ->name('album.photo');

// Public birth-date page — ลูกค้ากรอกวัน/เดือน/ปีเกิดเองจากลิงก์เฉพาะคน (ไม่ต้องล็อกอิน)
// เพื่อนร่วมทางกรอกข้อมูลของตัวเองผ่านลิงก์เฉพาะคน (ไม่ต้องล็อกอิน ไม่ต้องมีแอป)
Route::get('/p/{token}', [PublicPassengerFillController::class, 'show'])
    ->name('public.passenger-fill.show');
Route::post('/p/{token}', [PublicPassengerFillController::class, 'submit'])
    ->middleware('throttle:20,60')
    ->name('public.passenger-fill.submit');
Route::get('/p-done', [PublicPassengerFillController::class, 'done'])
    ->name('public.passenger-fill.done');

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

// Beam returnUrl — ปลายทางหลังลูกค้าจ่ายผ่านแอปธนาคารแล้วเด้งกลับ (ไม่ต้องล็อกอิน
// เพราะเบราว์เซอร์บนมือถืออาจกลับมาโดยไม่มี session เดิม) หน้านี้แค่รอผลจาก webhook
Route::get('/payment/return', PaymentReturnController::class)
    ->middleware('throttle:60,1')
    ->name('payment.return');
Route::get('/payment/return/{payment}/status', [PaymentReturnController::class, 'status'])
    ->middleware('throttle:120,1')
    ->name('payment.return.status');

// Public split-share payment — เพื่อนร่วมทริปจ่ายส่วนของตัวเองจากลิงก์ (ไม่ต้องล็อกอิน)
Route::get('/pay-share/{token}', [PublicSharePaymentController::class, 'show'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:60,1')
    ->name('public.pay-share.show');
Route::post('/pay-share/{token}', [PublicSharePaymentController::class, 'pay'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:payment')
    ->name('public.pay-share.submit');

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

// Legacy local-media → R2 fallback. Older content embeds absolute /storage/…
// URLs from before uploads moved to R2. On production nginx serves the file
// from disk while it still exists; once it has been copied to R2 and pruned
// (`php artisan media:migrate-to-r2 --prune`) the file is gone and the request
// reaches here, where we 301 it to the R2 equivalent so nothing 404s. Guarded
// to the R2-active case so it can never loop back on itself when the local
// 'public' disk is still the media disk (e.g. local dev). The local disk's
// 'serve' is disabled (config/filesystems.php) so this route owns /storage/*.
Route::get('/storage/{path}', function (string $path) {
    abort_unless(MediaDisk::name() === 'r2', 404);

    return redirect()->away(Storage::disk('r2')->url($path), 301);
})->where('path', '.*');

// SPA catch-all (must be last!)
//
// The shell is the same for every URL, but its <head> is not: SeoMeta resolves
// the title, description and share image for the path being requested so a link
// pasted into LINE or Facebook unfurls as the actual trip. Those crawlers never
// execute the JavaScript that would otherwise set them.
Route::get('/{any?}', function (?string $any = null) {
    return view('app', [
        'seo' => SeoMeta::for($any ?? '/'),
    ]);
})->where('any', '.*');
