<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/**
 * Standalone outbound owner без ProcessAttempt (bp-ddl-network). legacy_bridge временный:
 * owning cutovers P2.14–P2.19/P3.1–P3.6 переводят callers на Process ExecutionContext.
 */
enum OutboundOperationKind: string
{
    case LegacyBridge = 'legacy_bridge';
    case Diagnostic = 'diagnostic';
    case Probe = 'probe';
}
