<?php

use App\Http\Controllers\Promotion\PromotionApiController;
use App\Http\Middleware\PromotionJobToken;
use Illuminate\Support\Facades\Route;

// The daily promotion job's API (#616). Every route needs the job's token.
Route::prefix('promotion')->middleware(PromotionJobToken::class)->controller(PromotionApiController::class)->group(function () {
    Route::get('withdrawals', 'withdrawals');
    Route::post('withdrawals/settle', 'recordWithdrawn');

    Route::get('due', 'due');
    Route::post('due/check', 'stillDue');
    Route::post('promote', 'promote');
    Route::get('pending-deletions', 'pendingDeletions');
    Route::post('pending-deletions/clear', 'clearPromoted');

    Route::get('staged', 'readStaged');
    Route::post('staged/reserve', 'reserve');
    Route::post('staged/delete', 'discard');
    Route::post('staged/release', 'release');
    Route::get('staged/unclaimed', 'unclaimed');
    Route::get('staged/abandoned', 'abandoned');

    Route::get('games', 'games');
    Route::post('games/clear-staged', 'clearGameStagedTier');

    Route::get('meters', 'meters');
});
