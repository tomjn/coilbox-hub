<?php

use App\Http\Controllers\Engines\EngineApiController;
use Illuminate\Support\Facades\Route;

Route::get('engines', [EngineApiController::class, 'index']);
Route::options('engines', [EngineApiController::class, 'preflight']);
