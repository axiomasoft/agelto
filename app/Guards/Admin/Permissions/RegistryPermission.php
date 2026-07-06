<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum RegistryPermission: string
{
    case ViewAny = 'registry.view_any';
    case View = 'registry.view';
    case Create = 'registry.create';
    case Update = 'registry.update';
    case Delete = 'registry.delete';
    case Restore = 'registry.restore';
    case ForceDelete = 'registry.force_delete';
    case Replicate = 'registry.replicate';
    case Reorder = 'registry.reorder';
}
