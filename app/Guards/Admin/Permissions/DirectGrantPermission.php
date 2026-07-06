<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

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
}
