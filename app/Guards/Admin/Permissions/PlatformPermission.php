<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum PlatformPermission: string
{
    case ViewAny = 'platform.view_any';
    case View = 'platform.view';
    case Create = 'platform.create';
    case Update = 'platform.update';
    case Delete = 'platform.delete';
    case Restore = 'platform.restore';
    case ForceDelete = 'platform.force_delete';
    case Replicate = 'platform.replicate';
    case Reorder = 'platform.reorder';
}
