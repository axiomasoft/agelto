<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

enum InvariantKind: string
{
    case GroupMembership = 'invariant_group_membership';
    case DispatchGeneration = 'invariant_dispatch_generation';
    case WorkState = 'invariant_work_state';
    case AttemptState = 'invariant_attempt_state';
    case ReleasedMembers = 'invariant_released_members';
    case LaneOutstanding = 'invariant_lane_outstanding';
    case LaneRunning = 'invariant_lane_running';
}
