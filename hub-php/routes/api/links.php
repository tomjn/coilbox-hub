<?php

use App\Http\Controllers\Links\LinkCardController;
use Illuminate\Support\Facades\Route;

// Preview cards for links posted in Coilbox chat (#654).
Route::get('links/card', [LinkCardController::class, 'card'])->middleware('throttle:link-card');
Route::get('links/image', [LinkCardController::class, 'image'])->middleware('throttle:link-image');

Route::options('links/card', [LinkCardController::class, 'preflight']);
Route::options('links/image', [LinkCardController::class, 'preflight']);
