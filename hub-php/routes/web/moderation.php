<?php

use App\Http\Controllers\Moderation\AuthorsController;
use App\Http\Controllers\Moderation\GamesController;
use App\Http\Controllers\Moderation\MapPacksController;
use App\Http\Controllers\Moderation\MapsController;
use App\Http\Controllers\Moderation\OpsController;
use App\Http\Controllers\Moderation\PicturesController;
use App\Http\Controllers\Moderation\ReportsController;
use App\Http\Controllers\Moderation\TrailController;
use App\Http\Controllers\Moderation\UsersController;
use Illuminate\Support\Facades\Route;

// Every page and every write asks is_moderator() for itself. The Next app's
// server actions become plain form posts under the page they belong to.

Route::get('/moderation', [ReportsController::class, 'index']);
Route::post('/moderation/reports', [ReportsController::class, 'act']);

Route::get('/moderation/assets', [PicturesController::class, 'index']);
Route::post('/moderation/assets/approve', [PicturesController::class, 'approve']);
Route::post('/moderation/assets/reject', [PicturesController::class, 'reject']);
Route::post('/moderation/assets/acknowledge', [PicturesController::class, 'acknowledge']);
Route::get('/moderation/assets/{id}', [PicturesController::class, 'picture']);

Route::get('/moderation/trail', [TrailController::class, 'index']);
Route::post('/moderation/trail/return', [TrailController::class, 'return']);

Route::get('/moderation/maps', [MapsController::class, 'index']);
Route::post('/moderation/maps/clear', [MapsController::class, 'clear']);
Route::post('/moderation/maps/tags', [MapsController::class, 'tags']);
Route::post('/moderation/maps/feature', [MapsController::class, 'feature']);
Route::get('/moderation/maps/{slug}', [MapsController::class, 'show']);

Route::get('/moderation/map-packs', [MapPacksController::class, 'index']);
Route::post('/moderation/map-packs', [MapPacksController::class, 'create']);
Route::post('/moderation/map-packs/save', [MapPacksController::class, 'save']);
Route::post('/moderation/map-packs/feature', [MapPacksController::class, 'feature']);
Route::post('/moderation/map-packs/add', [MapPacksController::class, 'add']);
Route::post('/moderation/map-packs/remove', [MapPacksController::class, 'remove']);
Route::post('/moderation/map-packs/delete', [MapPacksController::class, 'delete']);
Route::get('/moderation/map-packs/{id}', [MapPacksController::class, 'show']);

Route::get('/moderation/authors', [AuthorsController::class, 'index']);
Route::post('/moderation/authors/merge', [AuthorsController::class, 'merge']);
Route::post('/moderation/authors/unmerge', [AuthorsController::class, 'unmerge']);

Route::get('/moderation/users', [UsersController::class, 'index']);

Route::get('/moderation/games', [GamesController::class, 'index']);
Route::post('/moderation/games/decide', [GamesController::class, 'decide']);
Route::post('/moderation/games/visibility', [GamesController::class, 'visibility']);
Route::post('/moderation/games/version-visibility', [GamesController::class, 'versionVisibility']);
Route::post('/moderation/games/feature', [GamesController::class, 'feature']);
Route::post('/moderation/games/offer', [GamesController::class, 'offer']);

Route::get('/ops', [OpsController::class, 'show']);
