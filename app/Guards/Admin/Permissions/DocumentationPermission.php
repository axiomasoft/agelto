<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use AzGuard\Attributes\RoleOnly;

enum DocumentationPermission: string
{
    #[RoleOnly]
    case View = 'documentation.view';
}
