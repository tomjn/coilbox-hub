<?php

use App\Http\Controllers\Replays\ReplaysController;
use Illuminate\Support\Facades\Route;

Route::get('/replays', [ReplaysController::class, 'index']);
Route::get('/replays/{gameId}', [ReplaysController::class, 'show']);
Route::get('/replays/{gameId}/heat.png', [ReplaysController::class, 'heat']);
Route::post('/replays/{gameId}/report', [ReplaysController::class, 'report']);
