<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Outcomes;

/**
 * Исход одной admit tx. Admitted — единственный grant; Superseded — fenced
 * invalidation stale input; остальные откатывают tx без записей.
 */
enum AdmissionStatus: string
{
    case Admitted = 'admitted';
    case WorkLocked = 'work_locked';
    case NotEligible = 'not_eligible';
    case Paused = 'paused';
    case HandlerUnavailable = 'handler_unavailable';
    case CapacityFull = 'capacity_full';
    case CohortCapped = 'cohort_capped';
    case CursorMoved = 'cursor_moved';
    case Superseded = 'superseded';
    case SnapshotChanged = 'snapshot_changed';
    case InvariantViolation = 'invariant_violation';
}
