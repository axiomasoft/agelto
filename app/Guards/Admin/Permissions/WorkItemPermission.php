<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum WorkItemPermission: string
{
    case ViewAny = 'work_item.view_any';
    case View = 'work_item.view';
}
