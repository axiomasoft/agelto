<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

/** Kind of a control authority row; equals the `prc_control_scopes.scope_kind` CHECK. */
enum ScopeKind: string
{
    case System = 'system';
    case Acquisition = 'acquisition';
    case Documents = 'documents';
    case Platform = 'platform';
    case Cohort = 'cohort';
    case Stage = 'stage';
}
