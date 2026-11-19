<?php

use App\Http\Controllers\Assets\AuthApiController;
use App\Support\Api;
use Illuminate\Support\Facades\Route;

Route::get('/auth', [AuthApiController::class, 'show']);
Route::options('/auth', fn () => Api::preflight());
Route::post('/auth/logout', [AuthApiController::class, 'logout']);
Route::options('/auth/logout', fn () => Api::preflight());
