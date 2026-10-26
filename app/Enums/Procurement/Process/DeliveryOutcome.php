<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

/**
 * Исход одной queue delivery для telemetry и тестов. Любой исход ACK-ит job: повторное исполнение
 * принадлежит durable ledger (reconciler), а не очереди.
 */
enum DeliveryOutcome: string
{
    /** Claimed: handler исполнен и результат передан WorkFinalizer. */
    case Executed = 'executed';
    /** Stale, duplicate, expired, paused, held, terminal или capacity wait: handler не вызывался. */
    case Rejected = 'rejected';
    /** Handler прекратил работу из-за потерянного lease: публикации нет. */
    case LeaseLost = 'lease_lost';
    /** DB недоступна: work остаётся durable в своём состоянии до scanners. */
    case DependencyUnavailable = 'dependency_unavailable';
    /** Неподдерживаемая queue schema: видимый incident, работа восстановится scanners. */
    case Quarantined = 'quarantined';
}
