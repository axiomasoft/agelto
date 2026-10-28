<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum ControlScopePermission: string
{
    case ViewAny = 'control_scope.view_any';
    case View = 'control_scope.view';
}
