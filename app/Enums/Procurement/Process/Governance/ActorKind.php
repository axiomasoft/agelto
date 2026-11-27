<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Governance;

/** Кто записал transition: actor_kind CHECK prc_process_transitions. */
enum ActorKind: string
{
    case System = 'system';
    case Operator = 'operator';
    case Worker = 'worker';
    case Reconciler = 'reconciler';
}
