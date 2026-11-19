<?php

use App\Http\Controllers\Games\GameApiController;
use Illuminate\Support\Facades\Route;

Route::get('games', [GameApiController::class, 'index']);
Route::post('games/facts', [GameApiController::class, 'facts']);
Route::post('games/branding', [GameApiController::class, 'branding']);
Route::post('games/{shortname}/downloaded', [GameApiController::class, 'downloaded']);

Route::options('games', [GameApiController::class, 'preflight']);
Route::options('games/facts', [GameApiController::class, 'preflight']);
Route::options('games/branding', [GameApiController::class, 'preflight']);
Route::options('games/{shortname}/downloaded', [GameApiController::class, 'preflight']);
