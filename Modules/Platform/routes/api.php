<?php

use Illuminate\Support\Facades\Route;
use \Platform\Http\Controllers\PlatformController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('platforms', PlatformController::class)->names('platform');
});
