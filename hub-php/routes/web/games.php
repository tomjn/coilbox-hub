<?php

use App\Http\Controllers\Games\ChangesController;
use App\Http\Controllers\Games\GameEditController;
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

// The owner's and moderators' writes, server actions in the Next app. Each
// asks who is posting before it writes.
Route::get('/games/{shortname}/edit', [GameEditController::class, 'edit']);
Route::post('/games/{shortname}/edit/details', [GameEditController::class, 'details']);
Route::post('/games/{shortname}/edit/factions', [GameEditController::class, 'factions']);
Route::post('/games/{shortname}/edit/downloads', [GameEditController::class, 'downloads']);
Route::post('/games/{shortname}/edit/image', [GameEditController::class, 'image']);
Route::post('/games/{shortname}/edit/image/remove', [GameEditController::class, 'removeImage']);
Route::post('/games/{shortname}/edit/visibility', [GameEditController::class, 'visibility']);
Route::post('/games/{shortname}/edit/version-visibility', [GameEditController::class, 'versionVisibility']);
Route::post('/games/{shortname}/edit/offer', [GameEditController::class, 'offer']);
Route::post('/games/{shortname}/edit/snippet', [GameEditController::class, 'snippet']);
Route::post('/games/{shortname}/ownership', [GameEditController::class, 'requestOwnership']);
