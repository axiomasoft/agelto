<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use App\Models\Procurement\Process\WorkItem;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;

#[RequiresGrant]
#[Resource(model: WorkItem::class)]
enum WorkItemPermission: string
{
    case ViewAny = 'work_item.view_any';
    case View = 'work_item.view';
}
