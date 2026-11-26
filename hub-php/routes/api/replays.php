<?php

use App\Http\Controllers\Replays\ReplayAnalysisController;
use App\Http\Controllers\Replays\ReplayUploadController;
use Illuminate\Support\Facades\Route;

Route::post('replays/uploads', [ReplayUploadController::class, 'store']);
Route::options('replays/uploads', [ReplayUploadController::class, 'preflight']);
Route::post('replays/{gameId}/analyses', [ReplayAnalysisController::class, 'store']);
Route::options('replays/{gameId}/analyses', [ReplayAnalysisController::class, 'preflight']);
