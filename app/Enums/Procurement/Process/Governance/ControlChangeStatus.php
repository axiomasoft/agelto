<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Governance;

/** Исход optimistic manual control mutation (pause/resume) одной scope authority row. */
enum ControlChangeStatus: string
{
    case Applied = 'applied';
    case Unchanged = 'unchanged';
    case VersionConflict = 'version_conflict';
    case NotFound = 'not_found';
}
