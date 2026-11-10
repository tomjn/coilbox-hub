<?php

use App\Http\Controllers\Games\ChangesController;
use App\Http\Controllers\Games\GamesController;
use App\Http\Controllers\Games\TreeController;
use App\Http\Controllers\Games\UnitController;
use App\Http\Controllers\Games\UnitsController;
use Illuminate\Support\Facades\Route;

// The Next app split each listing into a page that reads no query (served
// from the CDN) and a `filtered` page it rewrote to when a filter key was
// present. Here one controller reads the query either way.

Route::get('/games', [GamesController::class, 'index']);
Route::get('/games/{shortname}', [GamesController::class, 'show']);
Route::get('/games/{shortname}/units', [UnitsController::class, 'index']);
Route::get('/games/{shortname}/units/{unit}', [UnitController::class, 'show']);
Route::get('/games/{shortname}/units/{unit}/compare', [UnitController::class, 'compare']);
Route::get('/games/{shortname}/tree', [TreeController::class, 'show']);
Route::get('/games/{shortname}/changes', [ChangesController::class, 'show']);
