<?php

use Illuminate\Support\Facades\Route;
use \Sys\Http\Controllers\SysController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('sys', SysController::class)->names('sys');
});
