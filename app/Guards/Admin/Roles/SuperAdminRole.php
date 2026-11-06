<?php

declare(strict_types=1);

namespace App\Guards\Admin\Roles;

use AzGuard\Roles\BaseRole;
use Override;
use UnitEnum;

/**
 * Суперадмин: полный доступ ко всем правам панели admin.
 */
final class SuperAdminRole extends BaseRole
{
    #[Override]
    public function key(): string
    {
        return 'super-admin';
    }

    #[Override]
    public function label(): string
    {
        return 'Супер-администратор';
    }

    #[Override]
    public function superAdmin(): bool
    {
        return true;
    }

    #[Override]
    public function level(): int
    {
        return 1000;
    }

    /** @return list<UnitEnum|string> */
    #[Override]
    public function permissions(): array
    {
        return [];
    }
}
