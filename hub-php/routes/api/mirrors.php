<?php

use App\Http\Controllers\Mirrors\CatalogueController;
use Illuminate\Support\Facades\Route;

Route::get('catalogue', [CatalogueController::class, 'show']);
Route::get('catalogue.sig', [CatalogueController::class, 'sig']);

Route::options('catalogue', [CatalogueController::class, 'preflight']);
Route::options('catalogue.sig', [CatalogueController::class, 'preflight']);
