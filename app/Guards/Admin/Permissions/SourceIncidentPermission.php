<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum SourceIncidentPermission: string
{
    case ViewAny = 'source_incident.view_any';
    case View = 'source_incident.view';
    case Update = 'source_incident.update';
}
