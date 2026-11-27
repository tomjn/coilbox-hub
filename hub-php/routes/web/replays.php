<?php

use App\Http\Controllers\Replays\ReplaysController;
use Illuminate\Support\Facades\Route;

Route::get('/replays', [ReplaysController::class, 'index']);
