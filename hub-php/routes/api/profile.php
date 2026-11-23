<?php

use App\Http\Controllers\Gallery\PointsApiController;
use App\Http\Controllers\Gallery\ProfileApiController;
use App\Support\Api;
use Illuminate\Support\Facades\Route;

Route::get('/profile', [ProfileApiController::class, 'show']);
Route::options('/profile', fn () => Api::preflight());
Route::get('/profile/points', [PointsApiController::class, 'show']);
Route::options('/profile/points', fn () => Api::preflight());
