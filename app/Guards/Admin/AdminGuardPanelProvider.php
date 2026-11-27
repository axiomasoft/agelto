<?php

declare(strict_types=1);

namespace App\Guards\Admin;

use App\Models\User;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Sources\Database\DatabaseSource;

/**
 * AzGuard-панель admin.
 *
 * Регистрируется через `config/azguard.php` -> `panels.providers`, НЕ в bootstrap/providers.php.
 */
final class AdminGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'admin';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->default()
            ->label('Admin')
            ->for(User::class, guard: 'web')
            ->permissions([DatabaseSource::make()]);
    }
}
