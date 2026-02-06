<?php

use Illuminate\Support\Facades\Route;
use \Eis\Http\Controllers\EisController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('eis', EisController::class)->names('eis');
});
