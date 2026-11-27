<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum DocumentationPermission: string
{
    case View = 'documentation.view';
}
