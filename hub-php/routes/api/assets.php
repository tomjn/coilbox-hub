<?php

use App\Http\Controllers\Assets\AssetApiController;
use Illuminate\Support\Facades\Route;

Route::post('/assets/pictures', [AssetApiController::class, 'pictures']);
Route::options('/assets/pictures', [AssetApiController::class, 'preflight']);

Route::post('/assets/have', [AssetApiController::class, 'have']);
Route::options('/assets/have', [AssetApiController::class, 'preflight']);

Route::post('/assets/upload', [AssetApiController::class, 'upload']);
Route::options('/assets/upload', [AssetApiController::class, 'preflight']);
