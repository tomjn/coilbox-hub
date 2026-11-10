<?php

use App\Http\Controllers\Seo\OgImageController;
use App\Http\Controllers\Seo\SeoController;
use Illuminate\Support\Facades\Route;

// For crawlers. No session and no CSRF.
Route::withoutMiddleware('web')->group(function () {
    Route::get('/robots.txt', [SeoController::class, 'robots']);
    Route::get('/sitemap.xml', [SeoController::class, 'sitemap']);

    // Link preview cards, at the paths the Next app's `opengraph-image` files had.
    Route::get('/opengraph-image', [OgImageController::class, 'home']);
    Route::get('/item/{id}/opengraph-image', [OgImageController::class, 'item']);
    Route::get('/map/{slug}/opengraph-image', [OgImageController::class, 'map']);
    Route::get('/games/{shortname}/opengraph-image', [OgImageController::class, 'game']);
});
