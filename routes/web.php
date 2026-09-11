<?php

use App\Http\Controllers\Documentation\PrivateDocumentationController;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Authenticate::redirectUsing(fn (): string => route(name: 'filament.admin.auth.login'));

Route::get('/', function () {
    return view('welcome');
});

Route::match(['GET', 'HEAD'], 'documentation/{path?}', PrivateDocumentationController::class)
    ->where('path', '.*')
    ->middleware('auth')
    ->name('documentation.show');

Route::match(['GET', 'HEAD'], 'admin/documentation/{path?}', PrivateDocumentationController::class)
    ->where('path', '.*')
    ->middleware('auth')
    ->name('admin.documentation.show');
