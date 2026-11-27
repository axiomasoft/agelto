<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/**
 * Standalone outbound owner без ProcessAttempt. legacy_bridge обслуживает callers,
 * у которых нет собственного Process ExecutionContext.
 */
enum OutboundOperationKind: string
{
    case LegacyBridge = 'legacy_bridge';
    case Diagnostic = 'diagnostic';
    case Probe = 'probe';
}
