<?php

use App\Http\Controllers\Publish\EditController;
use App\Http\Controllers\Publish\PublishController;
use Illuminate\Support\Facades\Route;

Route::get('/publish', [PublishController::class, 'show']);
Route::post('/publish', [PublishController::class, 'publish']);

Route::get('/item/{id}/edit', [EditController::class, 'show']);
Route::post('/item/{id}/edit', [EditController::class, 'save']);
Route::post('/item/{id}/withdraw', [EditController::class, 'withdraw']);
