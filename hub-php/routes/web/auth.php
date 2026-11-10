<?php

use App\Http\Controllers\Auth\AccountController;
use App\Http\Controllers\Auth\SignInController;
use Illuminate\Support\Facades\Route;

Route::get('/sign-in', [SignInController::class, 'page']);
Route::post('/auth/signin', [SignInController::class, 'start']);
Route::get('/auth/callback', [SignInController::class, 'callback']);
Route::post('/auth/signout', [SignInController::class, 'signOut']);
Route::post('/auth/link', [AccountController::class, 'link']);

Route::get('/account', [AccountController::class, 'show']);
Route::post('/account/rename', [AccountController::class, 'rename']);
Route::post('/account/delete', [AccountController::class, 'delete']);
Route::post('/account/unlink', [AccountController::class, 'unlink']);
Route::post('/account/factor/start', [AccountController::class, 'startEnrolment']);
Route::post('/account/factor/confirm', [AccountController::class, 'confirmEnrolment']);
Route::post('/account/factor/remove', [AccountController::class, 'removeFactor']);
Route::get('/account/verify', [AccountController::class, 'verifyPage']);
Route::post('/account/verify', [AccountController::class, 'verify']);
