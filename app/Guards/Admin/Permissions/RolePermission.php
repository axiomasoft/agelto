<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum RolePermission: string
{
    case ViewAny = 'role.view_any';
    case View = 'role.view';
    case Create = 'role.create';
    case Update = 'role.update';
    case Delete = 'role.delete';
    case Restore = 'role.restore';
    case ForceDelete = 'role.force_delete';
    case Replicate = 'role.replicate';
    case Reorder = 'role.reorder';
    case AzGuardRolesViewAny = 'azguard-roles.view_any';
    case AzGuardRolesView = 'azguard-roles.view';
    case AzGuardRoleGrantsViewAny = 'azguard-role-grants.view_any';
    case AzGuardRoleGrantsView = 'azguard-role-grants.view';
    case AzGuardRoleGrantsCreate = 'azguard-role-grants.create';
    case AzGuardRoleGrantsUpdate = 'azguard-role-grants.update';
    case AzGuardRoleGrantsDelete = 'azguard-role-grants.delete';
}
