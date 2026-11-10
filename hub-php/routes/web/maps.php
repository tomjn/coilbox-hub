<?php

use App\Http\Controllers\Maps\MapsController;
use App\Http\Controllers\Maps\MapReportController;
use Illuminate\Support\Facades\Route;

// One action for the plain and the filtered listing. The Next app rewrote
// `/maps` with a filter key to `/maps/filtered`, which is kept as an alias.
Route::get('/maps', [MapsController::class, 'index']);
Route::get('/maps/filtered', [MapsController::class, 'index']);
Route::get('/map/{slug}', [MapsController::class, 'show']);
Route::post('/map/{slug}/report', [MapReportController::class, 'store']);
