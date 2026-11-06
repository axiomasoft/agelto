<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use App\Models\Procurement\Process\Transition;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;

#[RequiresGrant]
#[Resource(model: Transition::class)]
enum OperationLogPermission: string
{
    case ViewAny = 'operation_log.view_any';
    case View = 'operation_log.view';
}
