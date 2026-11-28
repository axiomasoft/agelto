<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Daemons;

use App\Enums\Procurement\Process\Governance\DaemonHealth;
use App\Enums\Procurement\Process\Governance\DaemonKind;
use App\Repositories\Procurement\Process\Authority\DaemonHeartbeatRepository;

/**
 * Единое правило health для `process:daemon --health` и ApplicationReadiness: нужен
 * хотя бы один instance КАЖДОГО активированного daemon со свежим last_tick_at и без ошибки.
 * Отсутствующий или stale heartbeat, как и ошибка всех живых instances роли, нарушает readiness.
 */
final readonly class DaemonReadiness
{
    public function __construct(private DaemonHeartbeatRepository $heartbeats, private DaemonPolicy $policy) {}

    public function of(DaemonKind $kind): DaemonHealth
    {
        return $this->heartbeats->health($kind, $this->policy->liveSeconds);
    }

    /** @return array<string, DaemonHealth> активированные daemons по значению kind */
    public function all(): array
    {
        $health = [];
        foreach (DaemonKind::activeKinds() as $kind) {
            $health[$kind->value] = $this->of($kind);
        }

        return $health;
    }

    public function ready(): bool
    {
        foreach ($this->all() as $health) {
            if ($health !== DaemonHealth::Healthy) {
                return false;
            }
        }

        return true;
    }
}
