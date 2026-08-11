<?php

use App\Http\Controllers\Documentation\PrivateDocumentationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::match(['GET', 'HEAD'], 'admin/documentation/{path?}', PrivateDocumentationController::class)
    ->where('path', '.*')
    ->name('admin.documentation.show');
