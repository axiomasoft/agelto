<?php

declare(strict_types=1);

namespace App\Guards\Admin\Roles;

use AzGuard\Permissions\PermissionKey;
use AzGuard\Roles\BaseRole;

/**
 * Суперадмин: wildcard-доступ ко всему через Gate::before (PermissionSet::isWildcard).
 * Живёт в Roles/ панели admin для discovery через guard:sync-roles.
 */
final class SuperAdminRole extends BaseRole
{
    public function getLevel(): int
    {
        return 1000;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return [PermissionKey::WILDCARD];
    }
}
