<?php

declare(strict_types=1);

namespace App\Guards\Admin;

use AzGuard\PanelProvider;
use AzGuard\Support\Panel;

/**
 * AzGuard-панель admin.
 *
 * Комплементарна Filament-плагину (`AzGuardPlugin::forPanel('admin')`): плагин
 * авто-каталогизирует permission'ы по Filament-ресурсам, а эта панель даёт
 * дом для PHP-ролей (`guard:sync-roles` глобит `Roles/*Role.php` по basePath
 * этого провайдера) и регистрирует панель в менеджере (листинг в Role/DirectGrant UI).
 *
 * Регистрируется через `config/az-guard.php` → `panels`, НЕ в bootstrap/providers.php.
 */
final class AdminGuardPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            // namespace для directory-discovery ролей (basePath выводится из
            // расположения этого файла рефлексией — app/Guards/Admin).
            ->namespace('App\\Guards\\Admin')
            // Permission-enum'ы (сгенерированы guard:filament:generate --source=enum).
            // Регистрация обязательна: ClassRoleGrantSource резолвит enum-пермишены
            // роли только если их класс в getPermissionEnums() панели. Авто-дискавери
            // из каталога Permissions/ убирает ручную синхронизацию при догенерации.
            ->permissionEnums($this->discoverPermissionEnums());
    }

    /**
     * Классы permission-enum'ов из app/Guards/Admin/Permissions/*Permission.php.
     *
     * @return list<class-string>
     */
    private function discoverPermissionEnums(): array
    {
        $files = glob(__DIR__.'/Permissions/*Permission.php') ?: [];

        return array_values(array_filter(array_map(
            fn (string $file): string => __NAMESPACE__.'\\Permissions\\'.basename($file, '.php'),
            $files,
        ), enum_exists(...)));
    }
}
