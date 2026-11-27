<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/** Исход audited network authority mutation (override/retire) под locked version fence. */
enum NetworkChangeStatus: string
{
    case Applied = 'applied';
    case Unchanged = 'unchanged';
    case VersionConflict = 'version_conflict';
    case NotFound = 'not_found';
}
