<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum ProcessRunPermission: string
{
    case ViewAny = 'process_run.view_any';
    case View = 'process_run.view';
}
