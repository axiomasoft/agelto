<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Enums\Procurement\Process\DaemonHealth;
use App\Enums\Procurement\Process\DaemonKind;
use App\Repositories\Procurement\Process\DaemonHeartbeatRepository;

/**
 * Единое правило health для `process:daemon --health` и ApplicationReadiness (bp-daemons): нужен
 * хотя бы один instance КАЖДОГО активированного daemon со свежим last_tick_at. Daemon, чья
 * активация принадлежит будущему пункту (project до P1.15), в readiness не входит.
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
