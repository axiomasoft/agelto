<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use App\Models\Procurement\Ai\Analysis;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;

#[RequiresGrant]
#[Resource(model: Analysis::class)]
enum AnalysisPermission: string
{
    case ViewAny = 'analysis.view_any';
    case View = 'analysis.view';
    case Create = 'analysis.create';
    case Update = 'analysis.update';
    case Delete = 'analysis.delete';
    case Restore = 'analysis.restore';
    case ForceDelete = 'analysis.force_delete';
    case Replicate = 'analysis.replicate';
    case Reorder = 'analysis.reorder';
}
