<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

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
}
