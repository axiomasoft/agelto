<?php

declare(strict_types=1);

use App\Http\Controllers\PlatformController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('platforms', PlatformController::class)->names('platform');
});
