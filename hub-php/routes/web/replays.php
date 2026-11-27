<?php

use App\Http\Controllers\Replays\ReplayDataController;
use App\Http\Controllers\Replays\ReplaysController;
use Illuminate\Support\Facades\Route;

Route::get('/replays', [ReplaysController::class, 'index']);
// Before the replay page, which would take `data` as a game id.
Route::get('/replays/data', [ReplayDataController::class, 'index']);
Route::get('/replays/data/{week}/{file}', [ReplayDataController::class, 'download'])->where('file', '[a-z_]+\\.csv');
Route::get('/replays/{gameId}', [ReplaysController::class, 'show']);
Route::get('/replays/{gameId}/heat.png', [ReplaysController::class, 'heat']);
Route::post('/replays/{gameId}/report', [ReplaysController::class, 'report']);
