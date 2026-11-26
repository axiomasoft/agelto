<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use AzGuard\Filament\Concerns\AuthorizesPage;
use Filament\Pages\Dashboard as BaseDashboard;

final class Dashboard extends BaseDashboard
{
    use AuthorizesPage;

    protected static ?string $azguardKey = 'dashboard';

    protected static ?int $navigationSort = -100;

    protected static ?string $navigationLabel = 'Обзор';
}
