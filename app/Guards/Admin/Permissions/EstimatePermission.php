<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use App\Models\Procurement\Estimate\Estimate;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;

#[RequiresGrant]
#[Resource(model: Estimate::class)]
enum EstimatePermission: string
{
    case ViewAny = 'estimate.view_any';
    case View = 'estimate.view';
    case Create = 'estimate.create';
    case Update = 'estimate.update';
    case Delete = 'estimate.delete';
    case Restore = 'estimate.restore';
    case ForceDelete = 'estimate.force_delete';
    case Replicate = 'estimate.replicate';
    case Reorder = 'estimate.reorder';
}
