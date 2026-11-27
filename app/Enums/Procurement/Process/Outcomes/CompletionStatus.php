<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Outcomes;

/**
 * Закрытый набор исходов WorkFinalizer::finish: Applied(WorkState),
 * LostOwnership (ноль domain writes) либо InvariantViolation (rollback, incident).
 */
enum CompletionStatus: string
{
    case Applied = 'applied';
    case LostOwnership = 'lost_ownership';
    case InvariantViolation = 'invariant_violation';
}
