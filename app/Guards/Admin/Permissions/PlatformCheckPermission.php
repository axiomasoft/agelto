<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum PlatformCheckPermission: string
{
    case ViewAny = 'platform_check.view_any';
    case View = 'platform_check.view';
    case Create = 'platform_check.create';
    case Update = 'platform_check.update';
    case Delete = 'platform_check.delete';
    case Restore = 'platform_check.restore';
    case ForceDelete = 'platform_check.force_delete';
    case Replicate = 'platform_check.replicate';
    case Reorder = 'platform_check.reorder';
}
