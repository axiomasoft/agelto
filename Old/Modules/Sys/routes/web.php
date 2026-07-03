<?php

use Illuminate\Support\Facades\Route;
use \Sys\Http\Controllers\SysController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('sys', SysController::class)->names('sys');
});
