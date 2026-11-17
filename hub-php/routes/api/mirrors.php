<?php

use App\Http\Controllers\Mirrors\CatalogueController;
use App\Http\Controllers\Mirrors\MismatchController;
use App\Http\Controllers\Mirrors\ReportController;
use App\Http\Controllers\Mirrors\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('catalogue', [CatalogueController::class, 'show']);
Route::get('catalogue.sig', [CatalogueController::class, 'sig']);

Route::options('catalogue', [CatalogueController::class, 'preflight']);
Route::options('catalogue.sig', [CatalogueController::class, 'preflight']);

Route::post('mirrors/report', [ReportController::class, 'store']);
Route::options('mirrors/report', [ReportController::class, 'preflight']);

Route::post('archives/mismatch', [MismatchController::class, 'store']);
Route::options('archives/mismatch', [MismatchController::class, 'preflight']);

Route::post('uploads', [UploadController::class, 'store']);
Route::get('uploads/{id}', [UploadController::class, 'show']);
Route::options('uploads', [UploadController::class, 'preflight']);
Route::options('uploads/{id}', [UploadController::class, 'preflight']);
