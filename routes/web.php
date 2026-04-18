<?php

use Illuminate\Support\Facades\Route;
use App\Models\Trip;

Route::get('/sitemap.xml', function () {
    $trips = Trip::where('status', 'published')->orWhere('status', 'active')->get();
    
    return response()->view('sitemap', [
        'trips' => $trips
    ])->header('Content-Type', 'text/xml');
});

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
