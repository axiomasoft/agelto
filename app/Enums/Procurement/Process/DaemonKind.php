<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

/**
 * Process daemons (bp-daemons). Все четыре активированы: relay/reconcile/feed — P1.10/P1.11, project —
 * P1.15 (registry reducers, receipts и totals существуют). `activated()` сохранён как единая точка,
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
