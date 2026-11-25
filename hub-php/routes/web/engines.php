<?php

use App\Http\Controllers\Engines\EnginesController;
use Illuminate\Support\Facades\Route;

Route::get('/engines', [EnginesController::class, 'index']);
Route::get('/engines/{version}/{platform}', [EnginesController::class, 'show']);
Route::post('/engines/{version}/{platform}/note', [EnginesController::class, 'note']);
Route::post('/engines/{version}/{platform}/withdraw', [EnginesController::class, 'withdraw']);
