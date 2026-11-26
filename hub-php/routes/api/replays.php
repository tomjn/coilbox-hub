<?php

use App\Http\Controllers\Replays\ReplayUploadController;
use Illuminate\Support\Facades\Route;

Route::post('replays/uploads', [ReplayUploadController::class, 'store']);
Route::options('replays/uploads', [ReplayUploadController::class, 'preflight']);
