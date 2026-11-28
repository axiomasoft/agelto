<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use App\Models\Procurement\Process\Incident;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;

#[RequiresGrant]
#[Resource(model: Incident::class)]
enum IncidentPermission: string
{
    case ViewAny = 'incident.view_any';
    case View = 'incident.view';
}
