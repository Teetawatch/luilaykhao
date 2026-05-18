<?php

use Illuminate\Support\Facades\Route;
use App\Models\Trip;

// Google Search Console verification
Route::get('/google2a5171f00ca2654e.html', function () {
    return response('google-site-verification: google2a5171f00ca2654e.html')
        ->header('Content-Type', 'text/html');
});

// XML Sitemap
Route::get('/sitemap.xml', function () {
    $trips = Trip::where('status', 'published')->orWhere('status', 'active')->get();
    
    return response()->view('sitemap', [
        'trips' => $trips
    ])->header('Content-Type', 'text/xml');
});

// Live Share Link — standalone tracking page (must be before the SPA catch-all)
Route::get('/track/{token}', function (string $token) {
    return response()->view('track', ['token' => $token]);
})->where('token', '[A-Za-z0-9]+');

// SPA catch-all (must be last!)
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');

