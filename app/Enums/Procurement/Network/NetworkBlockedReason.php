<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/**
 * Причина TransportBlocked: требуется решение/изменение конфигурации, а не время.
 * Отличается от Process BlockedReason: адаптер отображает её в StageBlocked без item failure.
 */
enum NetworkBlockedReason: string
{
    case Paused = 'paused';
    case TargetRetired = 'target_retired';
    case CredentialHold = 'credential_hold';
    case ConfigurationHold = 'configuration_hold';
    case PolicyReview = 'policy_review';
    case UrlPolicyDenied = 'url_policy_denied';
    case OwnerLost = 'owner_lost';
}
