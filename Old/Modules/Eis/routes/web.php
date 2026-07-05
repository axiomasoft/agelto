<?php

use Illuminate\Support\Facades\Route;
use \Eis\Http\Controllers\EisController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('eis', EisController::class)->names('eis');
});
