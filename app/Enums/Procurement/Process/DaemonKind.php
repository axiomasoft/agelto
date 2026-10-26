<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

/**
 * Process daemons (bp-daemons). Project существует как значение контракта, но его reducer registry
 * и receipts появляются в P1.14–P1.18: до тех пор команда его не запускает и readiness не ждёт.
 */
enum DaemonKind: string
{
    case Relay = 'relay';
    case Reconcile = 'reconcile';
    case Feed = 'feed';
    case Project = 'project';

    public function activated(): bool
    {
        return $this !== self::Project;
    }

    /** @return list<self> */
    public static function activeKinds(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $kind): bool => $kind->activated()));
    }
}
