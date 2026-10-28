<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum IncidentPermission: string
{
    case ViewAny = 'incident.view_any';
    case View = 'incident.view';
}
