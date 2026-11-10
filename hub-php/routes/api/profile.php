<?php

use App\Http\Controllers\Gallery\ProfileApiController;
use App\Support\Api;
use Illuminate\Support\Facades\Route;

Route::get('/profile', [ProfileApiController::class, 'show']);
Route::options('/profile', fn () => Api::preflight());
