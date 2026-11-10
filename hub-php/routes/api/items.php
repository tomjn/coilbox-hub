<?php

use App\Http\Controllers\Gallery\ItemsApiController;
use App\Support\Api;
use Illuminate\Support\Facades\Route;

Route::get('/items', [ItemsApiController::class, 'index']);
Route::post('/items', [ItemsApiController::class, 'store']);
Route::options('/items', fn () => Api::preflight());

Route::get('/items/{id}', [ItemsApiController::class, 'show']);
Route::options('/items/{id}', fn () => Api::preflight());

Route::post('/items/{id}/imported', [ItemsApiController::class, 'imported']);
Route::options('/items/{id}/imported', fn () => Api::preflight());
