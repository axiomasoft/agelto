<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use App\Models\Procurement\Process\ProcessRun;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;

#[RequiresGrant]
#[Resource(model: ProcessRun::class)]
enum ProcessRunPermission: string
{
    case ViewAny = 'process_run.view_any';
    case View = 'process_run.view';
}
