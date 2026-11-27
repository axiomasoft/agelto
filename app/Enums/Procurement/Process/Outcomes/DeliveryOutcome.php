<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Outcomes;

/**
 * Исход одной queue delivery для telemetry и тестов. Любой исход ACK-ит job: повторное исполнение
 * принадлежит durable ledger (reconciler), а не очереди.
 */
enum DeliveryOutcome: string
{
    /** Claimed: handler исполнен и результат передан WorkFinalizer. */
    case Executed = 'executed';
    /** Pre-execution rejection (stale, duplicate, expired, paused, held, terminal, capacity wait) или finalization invariant violation. */
    case Rejected = 'rejected';
    /** Handler прекратил работу из-за потерянного lease: публикации нет. */
    case LeaseLost = 'lease_lost';
    /** DB недоступна: work остаётся durable в своём состоянии до scanners. */
    case DependencyUnavailable = 'dependency_unavailable';
    /** Неподдерживаемая queue schema: видимый incident, работа восстановится scanners. */
    case Quarantined = 'quarantined';
}
