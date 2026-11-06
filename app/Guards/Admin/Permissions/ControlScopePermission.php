<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use App\Models\Procurement\Process\ControlScope;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;

#[RequiresGrant]
#[Resource(model: ControlScope::class)]
enum ControlScopePermission: string
{
    case ViewAny = 'control_scope.view_any';
    case View = 'control_scope.view';
}
