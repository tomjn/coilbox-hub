<?php

use App\Http\Controllers\Maps\MapApiController;
use Illuminate\Support\Facades\Route;

Route::post('maps', [MapApiController::class, 'submit']);
Route::post('maps/have', [MapApiController::class, 'have']);
Route::post('maps/lookup', [MapApiController::class, 'lookup']);
Route::get('map-packs', [MapApiController::class, 'packs']);

Route::options('maps', [MapApiController::class, 'preflight']);
Route::options('maps/have', [MapApiController::class, 'preflight']);
Route::options('maps/lookup', [MapApiController::class, 'preflight']);
Route::options('map-packs', [MapApiController::class, 'preflight']);
