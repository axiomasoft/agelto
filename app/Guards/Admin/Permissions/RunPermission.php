<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum RunPermission: string
{
    case ViewAny = 'run.view_any';
    case View = 'run.view';
    case Create = 'run.create';
    case Update = 'run.update';
    case Delete = 'run.delete';
    case Restore = 'run.restore';
    case ForceDelete = 'run.force_delete';
    case Replicate = 'run.replicate';
    case Reorder = 'run.reorder';
}
