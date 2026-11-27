<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Caller;

use App\Dto\Procurement\Network\Control\NetworkOwnerState;
use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Network\Outcome\TransportBlocked;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Models\Procurement\Proxyma\NetworkAuthority;
use App\Models\Procurement\Proxyma\TargetLink;
use App\Services\Procurement\Network\NetworkOwnerFence;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Closure;
use Illuminate\Support\Facades\DB;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantContext;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;

/** Короткий execution grant реального owner; callback и transport выполняются вне SQL. */
final class RuntimeCaller
{
    private ?TenantContext $context = null;

    /** @var list<string> */
    private array $targets = [];

    private bool $probe = false;

    private ?string $receipt = null;

    private ?string $operation = null;

    public function __construct(private NetworkOwnerFence $owners, private ProcessTransaction $transactions) {}

    public function context(): ?TenantContext
    {
        return $this->context;
    }

    /** @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $execution, Closure $callback,
        bool $allowPausedOwner = false): mixed
    {
        if ($this->context !== null || DB::transactionLevel() !== 0) {
            throw new AuthorizationDenied;
        }
        $source = DB::table('prc_source_targets')->where('id', $plan->targetId->value)->whereNull('retired_at')->first();
        if ($source === null || (! is_int($source->platform_id) && $source->platform_id !== null)
            || (! is_string($source->provider_key) && $source->provider_key !== null)) {
            throw new AdmissionRejected('owner_lost');
        }
        $provider = $source->provider_key === null ? null : new ProviderKey($source->provider_key);
        $lease = $execution->lease();
        $snapshot = $this->owners->snapshot($lease, $provider);
        $state = $this->transactions->run(function () use ($lease, $snapshot, $provider, $source, $allowPausedOwner): NetworkOwnerState {
            $state = $this->owners->lock($lease, $snapshot, $provider, $allowPausedOwner);
            if ($state instanceof TransportBlocked) {
                throw new AdmissionRejected($state->reason === NetworkBlockedReason::Paused ? 'owner_paused' : 'owner_lost');
            }
            if ($state->platformId !== $source->platform_id
                || $state->providerKey?->value !== $source->provider_key) {
                throw new AdmissionRejected('owner_lost');
            }
            if (DB::table((new NetworkAuthority)->getTable())->where('id', 'network')->sharedLock()->value('authority') !== 'proxyma') {
                throw new AdmissionRejected('owner_lost');
            }

            return $state;
        });
        $targets = DB::table((new TargetLink)->getTable().' as l')->join('prc_source_targets as s', 's.id', '=', 'l.source_target_id')
            ->where('l.tenant_key', TenantKey::SYSTEM)->where('s.platform_id', $source->platform_id)
            ->where('s.provider_key', $source->provider_key)->where('s.capability', $source->capability)->whereNull('s.retired_at')
            ->pluck('l.target_id')->all();
        $this->targets = array_values(array_filter($targets, is_string(...)));
        $this->context = new TenantContext(TenantKey::system(), new ActorRef('runtime', Uuid::random()->value));
        $this->operation = $plan->physicalRequestId->value;
        $this->probe = $state->purpose->value === 'probe';
        try {
            return $callback();
        } finally {
            $this->context = null;
            $this->targets = [];
            $this->probe = false;
            $this->operation = null;
        }
    }

    /** Уже отправленный provider сохраняет receipt по immutable ownership, даже после потери live lease.
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function recordReceipt(Uuid $physical, ExecutionContext $execution, Closure $callback): mixed
    {
        if ($this->context !== null || DB::transactionLevel() !== 0) {
            throw new AuthorizationDenied;
        }
        $lease = $execution->lease();
        $tables = PackageSchema::tables();
        $owned = DB::table($tables['operations'].' as o')->join($tables['attempts'].' as a', 'a.operation_id', '=', 'o.id')
            ->where('o.tenant_key', TenantKey::SYSTEM)->where('a.tenant_key', TenantKey::SYSTEM)
            ->where('o.id', $physical->value)->where('a.physical_attempt_id', $physical->value)
            ->where('o.owner_type', 'process_attempt')->where('o.owner_id', $lease->attemptId->value)
            ->where('o.owner_token', $lease->token->value)->where('o.owner_generation', $lease->generation)->exists();
        if (! $owned) {
            throw new AuthorizationDenied;
        }
        $this->context = new TenantContext(TenantKey::system(), new ActorRef('runtime', Uuid::random()->value));
        $this->receipt = $physical->value;
        try {
            return $callback();
        } finally {
            $this->context = null;
            $this->receipt = null;
        }
    }

    public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $id): void
    {
        if ($this->receipt !== null) {
            if ($this->context === null || ! $tenant->isSystem() || $actor->type !== 'runtime'
                || $actor->id !== $this->context->actor->id || $ability !== 'execution.receipt.record' || $id->value !== $this->receipt) {
                throw new AuthorizationDenied;
            }

            return;
        }
        if ($this->context === null || ! $tenant->isSystem() || $actor->type !== 'runtime'
            || $actor->id !== $this->context->actor->id || ! in_array($ability, [
                'policy.resolve', 'binding.execute', 'binding.view', 'catalog.target.read',
                'execution.operation.create', 'execution.reservation.create', 'execution.reservation.cancel',
                'execution.demand.enqueue', 'execution.demand.take', 'execution.demand.cancel',
                'execution.receipt.record', 'execution.artifact.create',
                'catalog.profile.read', 'catalog.profile.select', 'catalog.route.read', 'catalog.route.select', 'catalog.endpoint.read',
                ...($this->probe ? ['health.probe.claim'] : []),
            ], true) || ($ability === 'catalog.target.read' && ! in_array($id->value, $this->targets, true))
            || (str_starts_with($ability, 'execution.') && $ability !== 'execution.operation.create' && $id->value !== $this->operation)) {
            throw new AuthorizationDenied;
        }
        if ($ability === 'execution.operation.create') {
            $this->operation = $id->value;
        }
    }
}
