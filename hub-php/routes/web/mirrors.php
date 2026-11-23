<?php

use App\Http\Controllers\Mirrors\RequestController;
use Illuminate\Support\Facades\Route;

Route::get('/mirrors/new', [RequestController::class, 'show']);
Route::post('/mirrors/new', [RequestController::class, 'store']);
