<?php

use App\Http\Controllers\Assets\StagedPictureController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Pictures cached for a year must not carry a session cookie, or no shared cache will hold them.
$noSession = [StartSession::class, ShareErrorsFromSession::class, AddQueuedCookiesToResponse::class, EncryptCookies::class, PreventRequestForgery::class];

Route::get('/assets/staged/{path}', [StagedPictureController::class, 'staged'])
    ->where('path', '.+')
    ->withoutMiddleware($noSession);

Route::get('/assets/games/{shortname}/{kind}/{hash}', [StagedPictureController::class, 'game'])
    ->withoutMiddleware($noSession);
