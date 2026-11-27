<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Caller;

use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Network\Outcome\TransportBlocked;
use App\Dto\Procurement\Platform\Definition\RequestProfile;
use App\Models\Procurement\Proxyma\CutoverJournal;
use App\Models\Procurement\Proxyma\NetworkAuthority;
use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Proxyma\Catalog\ProcessOwnerAuthority;
use App\Services\Procurement\Proxyma\Catalog\TargetCatalogAdapter;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Proxyma\Contracts\LockedAdmissionGuard;
use Proxyma\Contracts\SqlConnectionParticipant;
use Proxyma\Domain\Execution\AdmissionLockTier;
use Proxyma\Domain\Execution\ClaimDenialReason;
use Proxyma\Domain\Execution\ClaimDenied;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Infrastructure\Database\PackageSchema;
use WeakMap;

final class AdapterSendGuard implements LockedAdmissionGuard, SqlConnectionParticipant
{
    /** @var WeakMap<OutboundRequestPlan, true> */
    private WeakMap $plans;

    public function __construct(
        private readonly ProcessOwnerAuthority $owners,
        private readonly TargetCatalogAdapter $targets,
        private readonly AdapterHealthRepository $adapters,
    ) {
        $this->plans = new WeakMap;
    }

    public function connection(): Connection
    {
        return $this->owners->connection();
    }

    public function tier(): AdmissionLockTier
    {
        return AdmissionLockTier::AdapterGuard;
    }

    public function prepare(OutboundRequestPlan $plan): void
    {
        $existing = $this->planFor($plan->physicalRequestId->value);
        if ($existing !== null && $existing !== $plan) {
            throw new \LogicException('Physical request context is immutable.');
        }
        $this->plans[$plan] = true;
    }

    public function evaluateLocked(WireRequest $request, LockedOwnerState $owner, LockedTransactionContext $transaction): ?ClaimDenied
    {
        $state = $this->owners->networkState($owner->owner, $transaction);
        $plan = $this->planFor(physicalId: $request->physicalAttemptId->value, operationId: $request->operationId->value);
        $source = $this->targets->sourceFor($request->targetId);
        $deny = static fn (): ClaimDenied => new ClaimDenied(ClaimDenialReason::GuardDenied);
        if ($state === null || $plan === null || $source === null
            || $source->id !== $plan->targetId->value || (int) $source->policy_version !== (int) $source->source_revision
            || $state->platformId !== $source->platform_id || $state->providerKey?->value !== $source->provider_key
            || $request->method !== $plan->method || $request->url->reveal() !== $plan->url->reveal()
            || $request->body?->hash->hex !== $plan->bodyHash?->hex
            || $request->purpose !== $owner->purpose) {
            return $deny();
        }
        $target = LedgerRow::one('SELECT * FROM prc_source_targets WHERE id=? AND retired_at IS NULL', [$source->id]);
        if ($target === null || $target->string('canonical_origin') !== $plan->url->origin->value()
            || ! self::profileMatches($target, $plan->profile)
            || $request->timeouts->connectTimeoutMs > $plan->profile->connectSeconds * 1000
            || $request->timeouts->readTimeoutMs > $plan->profile->lowSpeedSeconds * 1000
            || $request->timeouts->totalTimeoutMs > $plan->profile->totalSeconds * 1000) {
            return $deny();
        }

        if ($this->adapters->lockForSend($target, $plan, $state) instanceof TransportBlocked) {
            return $deny();
        }
        $selector = $this->connection()->table((new NetworkAuthority)->getTable())->where('id', 'network')->first();
        if ($selector?->authority !== 'proxyma' || $selector->paused) {
            return $deny();
        }
        $journal = $this->connection()->table((new CutoverJournal)->getTable())->where('id', $selector->cutover_id)
            ->where('generation', $selector->generation)->whereIn('phase', ['switched', 'accepted']);
        $reserved = $this->connection()->table(PackageSchema::tables()['permits'])
            ->where('operation_id', $request->operationId->value)
            ->where('physical_attempt_id', $request->physicalAttemptId->value)->where('state', 'reserved')->exists();
        if (! $reserved) {
            return $journal->exists() ? null : $deny();
        }
        $marked = $journal->update(['first_authorized_at' => DB::raw('COALESCE(first_authorized_at, clock_timestamp())')]);

        return $marked === 1 ? null : $deny();
    }

    private function planFor(string $physicalId, ?string $operationId = null): ?OutboundRequestPlan
    {
        $operationPlan = null;
        foreach ($this->plans as $plan => $_) {
            if ($plan->physicalRequestId->value === $physicalId) {
                return $plan;
            }
            if ($plan->physicalRequestId->value === $operationId) {
                $operationPlan = $plan;
            }
        }

        return $operationPlan;
    }

    private static function profileMatches(LedgerRow $target, RequestProfile $profile): bool
    {
        if (! in_array($target->int('endpoint_policy_schema_version'), [1, 2], true)) {
            return false;
        }
        $policy = json_decode($target->string('endpoint_policy'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($policy) || ! is_array($policy['profiles'] ?? null)) {
            return false;
        }
        foreach ($policy['profiles'] as $candidate) {
            if (is_array($candidate) && RequestProfile::fromArray($candidate) == $profile) {
                return true;
            }
        }

        return false;
    }
}
