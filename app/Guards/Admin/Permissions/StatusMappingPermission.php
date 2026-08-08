<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum StatusMappingPermission: string
{
    case ViewAny = 'status_mapping.view_any';
    case View = 'status_mapping.view';
    case Create = 'status_mapping.create';
    case Update = 'status_mapping.update';
    case Delete = 'status_mapping.delete';
    case Restore = 'status_mapping.restore';
    case ForceDelete = 'status_mapping.force_delete';
    case Replicate = 'status_mapping.replicate';
    case Reorder = 'status_mapping.reorder';
}
