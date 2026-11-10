<?php

use App\Http\Controllers\Gallery\ContainerController;
use App\Http\Controllers\Gallery\DevelopersController;
use App\Http\Controllers\Gallery\FeedController;
use App\Http\Controllers\Gallery\GalleryController;
use App\Http\Controllers\Gallery\ItemController;
use App\Http\Controllers\Gallery\ProfileController;
use App\Support\Api;
use Illuminate\Support\Facades\Route;

Route::get('/', [GalleryController::class, 'home']);
Route::get('/gallery', [GalleryController::class, 'index']);
// The Next app's rewrite target. Kept so a link to it still works.
Route::get('/gallery/filtered', [GalleryController::class, 'index']);

Route::get('/item/{id}', [ItemController::class, 'show']);
Route::get('/item/{id}/withdrawn', [ItemController::class, 'withdrawn']);
Route::post('/item/{id}/report', [ItemController::class, 'report']);
Route::post('/item/{id}/feature', [ItemController::class, 'feature']);

Route::get('/profile/{id}', [ProfileController::class, 'show']);

// The desktop app fetches these. No session and no CSRF, like the API.
Route::withoutMiddleware('web')->group(function () {
    Route::get('/i/{id}', [ContainerController::class, 'show']);
    Route::options('/i/{id}', fn () => Api::preflight());
    Route::get('/export', [ContainerController::class, 'export']);
    Route::options('/export', fn () => Api::preflight());
    Route::get('/feed.xml', [FeedController::class, 'show']);
});

Route::get('/developers', [DevelopersController::class, 'show']);
