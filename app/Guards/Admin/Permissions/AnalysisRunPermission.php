<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use App\Models\Procurement\Ai\AnalysisRun;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;

#[RequiresGrant]
#[Resource(model: AnalysisRun::class)]
enum AnalysisRunPermission: string
{
    case ViewAny = 'analysis_run.view_any';
    case View = 'analysis_run.view';
}
