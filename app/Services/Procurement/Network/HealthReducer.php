<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\HealthMutation;
use App\Dto\Procurement\Network\HealthSnapshot;
use App\Enums\Procurement\Network\CircuitState as C;
use App\Enums\Procurement\Network\HealthScope;
use App\Enums\Procurement\Network\HealthSignal as S;
use App\Enums\Procurement\Network\TargetAvailability as A;

/** Pure reducer. Repository proves dedup, current epoch and exact probe ownership first. */
final readonly class HealthReducer
{
    public function __construct(private HealthPolicy $policy) {}

    public function reduce(HealthSnapshot $row, S $signal, string $reason, \DateTimeImmutable $now,
        int $sample, int $failures, int $independentGroups, bool $recentTargetFailure, ?\DateTimeImmutable $maintenanceUntil = null): ?HealthMutation
    {
        $probe = $row->state === C::HalfOpen;
        if ($signal === S::Ignored) {
            // A cancelled/irrelevant probe releases its entire claim without punishing the route.
            return $probe ? new HealthMutation(C::Unknown, $row->epoch + 1, $row->consecutive, $row->openings,
                $row->reason, null, null, $row->availability) : null;
        }
        $target = $row->scope === HealthScope::Target;
        $success = $signal === S::Success;
        $consecutive = $success ? 0 : $row->consecutive + 1;
        $open = $signal === S::CredentialHold || $signal === S::Maintenance;
        if ($target) {
            $open = $open || (! $success && $independentGroups >= 2);
        } elseif (! $success) {
            $open = $open || $probe || ($row->scope === HealthScope::Route ? $consecutive >= $this->policy->globalFailures
                : $consecutive >= $this->policy->targetFailures || ($sample >= $this->policy->targetSample && $failures * 100 >= $sample * $this->policy->targetPercent));
        }
        $availability = $target ? ($open ? A::Unavailable : ((! $success || $recentTargetFailure) ? A::Suspect : A::Available)) : null;
        if ($open) {
            $base = match ($row->scope) {
                HealthScope::Route => $this->policy->globalBase,
                HealthScope::RouteTarget => $this->policy->targetBase,
                HealthScope::Target => $this->policy->originCooldown,
            };
            $cap = match ($row->scope) {
                HealthScope::Route => $this->policy->globalCap,
                HealthScope::RouteTarget => $this->policy->targetCap,
                HealthScope::Target => $this->policy->originCooldown,
            };
            $until = $signal === S::CredentialHold ? null : $now->modify('+'.min($cap, $base * 2 ** min(20, $row->openings)).' seconds');
            if ($maintenanceUntil !== null && ($until === null || $maintenanceUntil > $until)) {
                $until = $maintenanceUntil;
            }

            return new HealthMutation(C::Open, $row->epoch + 1, 0, $row->openings + 1, $reason, $until, null, $availability);
        }
        $stable = $success ? ($row->stableSince ?? $now) : null;
        $openings = $row->openings;
        if ($success && $stable !== null && $now >= $stable->modify('+'.$this->policy->stableDecay.' seconds') && $openings > 0) {
            $openings--;
            $stable = $now;
        }

        // Unknown attribution keeps target suspect and permits evaluation of another route.
        return new HealthMutation(C::Closed, $row->epoch, $consecutive, $openings, $success ? null : $reason, null, $stable, $availability);
    }
}
