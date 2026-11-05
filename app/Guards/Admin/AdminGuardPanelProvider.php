<?php

declare(strict_types=1);

namespace App\Guards\Admin;

use AzGuard\Filament\Resources\RoleResource\RelationManagers\RoleUsersRelationManager;
use AzGuard\Models\Role;
use AzGuard\Models\RolePermission;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelProvider;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use Override;
use UnitEnum;

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
    #[Override]
    public function boot(): void
    {
        parent::boot();

        RolePermission::saved(static function (RolePermission $rolePermission): void {
            self::flushRolePermissionUsers($rolePermission);
        });

        RolePermission::deleted(static function (RolePermission $rolePermission): void {
            self::flushRolePermissionUsers($rolePermission);
        });

        AttachAction::configureUsing(static function (AttachAction $action): void {
            $action->after(static function (AttachAction $action, array $data, Component $livewire): void {
                if (! $livewire instanceof RoleUsersRelationManager) {
                    return;
                }

                self::flushUserPermissions($action->getRecord());
                self::flushRoleRecordUsers($livewire->getOwnerRecord());

                $recordId = $data['recordId'] ?? $data['id'] ?? null;
                if ($recordId !== null) {
                    self::flushUsersByIds(is_array($recordId) ? $recordId : [$recordId]);
                }
            });
        });

        DetachAction::configureUsing(static function (DetachAction $action): void {
            $action->after(static function (DetachAction $action, ?Model $record, Component $livewire): void {
                if (! $livewire instanceof RoleUsersRelationManager) {
                    return;
                }

                self::flushUserPermissions($record ?? $action->getRecord());
                self::flushRoleRecordUsers($livewire->getOwnerRecord());
            });
        });

        DetachBulkAction::configureUsing(static function (DetachBulkAction $action): void {
            $action->after(static function (Component $livewire): void {
                if (! $livewire instanceof RoleUsersRelationManager) {
                    return;
                }

                $selectedIds = $livewire->selectedTableRecords;

                if ($selectedIds !== []) {
                    self::flushUsersByIds($selectedIds);
                }

                self::flushRoleRecordUsers($livewire->getOwnerRecord());
            });
        });
    }

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
     * @return list<class-string<UnitEnum>>
     */
    private function discoverPermissionEnums(): array
    {
        $files = glob(__DIR__.'/Permissions/*Permission.php') ?: [];

        $classes = array_map(
            fn (string $file): string => __NAMESPACE__.'\\Permissions\\'.basename($file, '.php'),
            $files,
        );

        return array_values(array_filter(
            $classes,
            static fn (string $class): bool => enum_exists($class) && is_subclass_of($class, UnitEnum::class),
        ));
    }

    private static function flushRolePermissionUsers(RolePermission $rolePermission): void
    {
        $rolePermission->role?->users()->each(static function (Model $user): void {
            self::flushUserPermissions($user);
        });

        $originalRoleId = $rolePermission->getOriginal('role_id');
        if ((is_int($originalRoleId) || is_string($originalRoleId)) && $originalRoleId !== $rolePermission->role_id) {
            Role::query()->whereKey($originalRoleId)->first()?->users()->each(static function (Model $user): void {
                self::flushUserPermissions($user);
            });
        }
    }

    private static function flushRoleRecordUsers(?Model $ownerRecord): void
    {
        if (! $ownerRecord instanceof Role) {
            return;
        }

        $ownerRecord->users()->each(static function (Model $user): void {
            self::flushUserPermissions($user);
        });
    }

    /**
     * @param  array<array-key, mixed>  $ids
     */
    private static function flushUsersByIds(array $ids): void
    {
        $userModel = config('auth.providers.users.model');
        if (! is_string($userModel) || ! is_subclass_of($userModel, Model::class)) {
            return;
        }

        $userModel::query()->whereKey($ids)->get()->each(static function (Model $user): void {
            self::flushUserPermissions($user);
        });
    }

    private static function flushUserPermissions(mixed $user): void
    {
        if (! is_object($user)) {
            return;
        }

        if (method_exists($user, 'flushPermissions')) {
            $user->flushPermissions();
        }

        if ($user instanceof Model) {
            $user->unsetRelation('roles');

            $authUser = auth()->user();
            if ($authUser instanceof Model && $authUser->getKey() === $user->getKey()) {
                $authUser->unsetRelation('roles');
            }
        }
    }
}
