<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum OperationLogPermission: string
{
    case ViewAny = 'operation_log.view_any';
    case View = 'operation_log.view';
}
