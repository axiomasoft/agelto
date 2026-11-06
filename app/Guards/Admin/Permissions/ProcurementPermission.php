<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use App\Models\Procurement\Procurement;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;

#[RequiresGrant]
#[Resource(model: Procurement::class)]
enum ProcurementPermission: string
{
    case ViewAny = 'procurement.view_any';
    case View = 'procurement.view';
    case Create = 'procurement.create';
    case Update = 'procurement.update';
    case Delete = 'procurement.delete';
    case Restore = 'procurement.restore';
    case ForceDelete = 'procurement.force_delete';
    case Replicate = 'procurement.replicate';
    case Reorder = 'procurement.reorder';
}
