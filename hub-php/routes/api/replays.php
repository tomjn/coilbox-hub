<?php

use App\Http\Controllers\Replays\ReplayAnalysisController;
use App\Http\Controllers\Replays\ReplayTrackController;
use App\Http\Controllers\Replays\ReplayUploadController;
use Illuminate\Support\Facades\Route;

Route::post('replays/uploads', [ReplayUploadController::class, 'store']);
Route::options('replays/uploads', [ReplayUploadController::class, 'preflight']);
Route::get('replays/{gameId}/uploads/status', [ReplayUploadController::class, 'status']);
Route::options('replays/{gameId}/uploads/status', [ReplayUploadController::class, 'preflight']);
Route::post('replays/{gameId}/analyses', [ReplayAnalysisController::class, 'store']);
Route::options('replays/{gameId}/analyses', [ReplayAnalysisController::class, 'preflight']);
Route::get('replays/{gameId}/tracks', [ReplayTrackController::class, 'index']);
Route::options('replays/{gameId}/tracks', [ReplayTrackController::class, 'preflight']);
Route::post('replays/{gameId}/tracks/uploads', [ReplayTrackController::class, 'store']);
Route::options('replays/{gameId}/tracks/uploads', [ReplayTrackController::class, 'preflight']);
