<?php

use App\Http\Controllers\Publish\EditController;
use App\Http\Controllers\Publish\PublishController;
use Illuminate\Support\Facades\Route;

Route::get('/publish', [PublishController::class, 'show']);
Route::post('/publish', [PublishController::class, 'publish']);

// Games and maps come from Coilbox, not from Publish. This page says so.
Route::view('/add', 'publish.add', [
    'title' => 'Add your game or map',
    'description' => 'How a game or map gets onto Coilbox Hub, and how to claim a game you made.',
]);

Route::get('/item/{id}/edit', [EditController::class, 'show']);
Route::post('/item/{id}/edit', [EditController::class, 'save']);
Route::post('/item/{id}/withdraw', [EditController::class, 'withdraw']);
