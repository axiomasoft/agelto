<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum DirectGrantPermission: string
{
    case ViewAny = 'direct_grant.view_any';
    case View = 'direct_grant.view';
    case Create = 'direct_grant.create';
    case Update = 'direct_grant.update';
    case Delete = 'direct_grant.delete';
    case Restore = 'direct_grant.restore';
    case ForceDelete = 'direct_grant.force_delete';
    case Replicate = 'direct_grant.replicate';
    case Reorder = 'direct_grant.reorder';
    case AzGuardPermissionGrantsViewAny = 'azguard-permission-grants.view_any';
    case AzGuardPermissionGrantsView = 'azguard-permission-grants.view';
    case AzGuardPermissionGrantsCreate = 'azguard-permission-grants.create';
    case AzGuardPermissionGrantsUpdate = 'azguard-permission-grants.update';
    case AzGuardPermissionGrantsDelete = 'azguard-permission-grants.delete';
    case AzGuardPermissionsViewAny = 'azguard-permissions.view_any';
    case AzGuardPermissionsView = 'azguard-permissions.view';
    case AzGuardPermissionsCreate = 'azguard-permissions.create';
    case AzGuardPermissionsUpdate = 'azguard-permissions.update';
    case AzGuardPermissionsDelete = 'azguard-permissions.delete';
}
