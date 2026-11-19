<?php

use App\Http\Controllers\Downloads\DownloadRedirectController;
use App\Http\Controllers\Maps\MapEditController;
use App\Http\Controllers\Maps\MapsController;
use App\Http\Controllers\Maps\MapReportController;
use Illuminate\Support\Facades\Route;

// One action for the plain and the filtered listing. The Next app rewrote
// `/maps` with a filter key to `/maps/filtered`, which is kept as an alias.
Route::get('/maps', [MapsController::class, 'index']);
Route::get('/maps/filtered', [MapsController::class, 'index']);
Route::get('/map/{slug}', [MapsController::class, 'show']);
// Counted, then sent on to the file.
Route::get('/map/{slug}/download/{n}', [DownloadRedirectController::class, 'map'])->whereNumber('n');
Route::post('/map/{slug}/report', [MapReportController::class, 'store']);

// A map's owner, an owner of one of its authors, or a moderator. Each write
// asks can_edit_map before it writes.
Route::get('/map/{slug}/edit', [MapEditController::class, 'edit']);
Route::post('/map/{slug}/edit/downloads', [MapEditController::class, 'downloads']);
Route::post('/map/{slug}/ownership', [MapEditController::class, 'requestOwnership']);
