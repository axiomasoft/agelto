<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Governance;

/**
 * Process daemons. Все четыре роли активированы: relay/reconcile/feed/project.
 * `activated()` — единая точка,
 * через которую readiness и команда решают, какой daemon обязан быть живым.
 */
enum DaemonKind: string
{
    case Relay = 'relay';
    case Reconcile = 'reconcile';
    case Feed = 'feed';
    case Project = 'project';

    public function activated(): bool
    {
        return match ($this) {
            self::Relay, self::Reconcile, self::Feed, self::Project => true,
        };
    }

    /** @return list<self> */
    public static function activeKinds(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $kind): bool => $kind->activated()));
    }
}
