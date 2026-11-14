<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\ProxymaServiceProvider;

return [
    AppServiceProvider::class,
    ProxymaServiceProvider::class,
    AdminPanelProvider::class,
    HorizonServiceProvider::class,
];
