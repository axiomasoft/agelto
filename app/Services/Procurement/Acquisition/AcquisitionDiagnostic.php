<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Procurement\Acquisition\DetailFetched;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Acquisition\FetchItemRejected;
use App\Dto\Procurement\Acquisition\ListingFetched;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Network\AdapterInspection;
use App\Dto\Procurement\Network\AdapterValidationClaim;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Platform\DiagnosticResponse;
use App\Dto\Procurement\Platform\PlatformDefinition;
use App\Dto\Procurement\Platform\SupportedDetailCapability;
use App\Dto\Procurement\Platform\SupportedListingCapability;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\FailureScope;
use App\Exceptions\Procurement\Network\OutboundOwnershipLost;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Exceptions\Procurement\Process\ExecutionInterrupted;
use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Repositories\Procurement\Network\OutboundOperationRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Network\AdapterCanaryRouteSelector;
use App\Services\Procurement\Network\OutboundExecutor;
use App\Services\Procurement\Network\ScheduledAttemptGate;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Platform\Contracts\DetailSource;
use App\Services\Procurement\Platform\Contracts\ListingSource;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Proxy\RoutePolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/** Bounded read-only canary. It has no work, cursor or canonical publication authority. */
final readonly class AcquisitionDiagnostic
{
    public function __construct(private AdapterHealthRepository $health, private OutboundOperationRepository $operations,
        private ProcessTransaction $transactions, private OutboundExecutor $executor,
        private AcquisitionArtifacts $artifacts, private ?UrlPolicy $urls = null) {}

    public function fetch(PlatformDefinition $definition, int $platformId, ListingRequest|DetailRequest $request,
        RoutePolicy $routes, ContentHash $inputHash, ?UrlPolicy $urls = null): DiagnosticResponse
    {
        $capability = $request instanceof ListingRequest ? $definition->listing : $definition->detail;
        if (! $capability instanceof SupportedListingCapability && ! $capability instanceof SupportedDetailCapability) {
            throw new \InvalidArgumentException('Unsupported diagnostic capability.');
        }
        $endpoint = $definition->endpoints->get($capability->endpoints[0]);
        $target = $definition->sourceTargets->targets[$endpoint->targetKey] ?? throw new \LogicException('Missing diagnostic target.');
        $contract = $target->adapterContract ?? throw new \LogicException('Missing diagnostic contract.');
        $catalog = LedgerRow::one('SELECT id FROM prc_source_targets WHERE platform_id=? AND target_key=? AND retired_at IS NULL
            AND endpoint_policy_schema_version=2 AND endpoint_policy->\'adapter_contract\'=?::jsonb',
            [$platformId, $target->key, json_encode($contract->toArray(), JSON_THROW_ON_ERROR)])
            ?? throw new \InvalidArgumentException('Diagnostic catalog is absent or incompatible.');
        $token = new ProcessUuid((string) Str::uuid());
        $handle = $this->transactions->run(fn () => $this->operations->open(new ProcessUuid((string) Str::uuid()), $token,
            OutboundOperationKind::Probe, $platformId, 'acquisition.diagnostic', $contract->fingerprint(),
            ['system', 'monitoring'], 300, 300, probeToken: $token));
        $owner = new OutboundExecutionContext($handle,
            fn ($lease) => $this->transactions->run(fn () => $this->operations->renew($lease, 300)), null, 300, 15);
        try {
            $claim = $this->transactions->run(fn () => $this->health->admit($contract, $owner));
            if (! $claim instanceof AdapterValidationClaim) {
                throw new OutboundUnavailable($claim);
            }
            $owner->bindAdapter($claim);
            $selector = app()->makeWith(AdapterCanaryRouteSelector::class, ['context' => $owner, 'routes' => $routes]);
            $gate = app()->makeWith(ScheduledAttemptGate::class, ['routes' => $selector]);
            $outbound = new AcquisitionPlatformOutbound($this->executor, $gate, $urls ?? $this->urls ?? new UrlPolicy($definition->sourceTargets->origins()),
                new TransferBudget(134217728, 134217728), $owner, $owner, $this->artifacts);
            $container = clone app();
            $container->instance(PlatformDefinition::class, $definition);
            $container->instance(PlatformOutbound::class, $outbound);
            $container->instance(ProcessUuid::class, new ProcessUuid($catalog->string('id')));
            $source = $container->make($capability->implementation);
            if (($request instanceof ListingRequest && ! $source instanceof ListingSource)
                || ($request instanceof DetailRequest && ! $source instanceof DetailSource)) {
                throw new \LogicException('Invalid diagnostic adapter binding.');
            }
            try {
                $result = $source->fetch($request, $owner);
            } catch (\Throwable $failure) {
                if (! $failure instanceof ExecutionInterrupted && ! $failure instanceof QueryException
                    && ! $failure instanceof OutboundOwnershipLost && ! $failure instanceof OutboundUnavailable
                    && $owner->successfulRequestIds() !== []) {
                    $inspection = new AdapterInspection($failure, AdapterVerdict::HandlerFault, 'acquisition.diagnostic_fault');
                    $this->transactions->run(fn () => $this->health->observe($owner, $inputHash, $inspection));
                }
                throw $failure;
            }
            $requestsUsed = LedgerRow::one('SELECT count(*)::bigint AS total FROM prc_network_permits p JOIN prc_network_demands d ON d.id=p.demand_id WHERE d.operation_id=? AND p.authorized_at IS NOT NULL', [$handle->id->value])?->int('total') ?? 0;
            if ($owner->successfulRequestIds() === []) {
                return new DiagnosticResponse($result, null, $requestsUsed);
            }
            $verdict = ($result instanceof ListingFetched && in_array($result->page->assessment->completeness, [Completeness::Complete, Completeness::CompleteEmpty], true))
                || ($result instanceof DetailFetched && $result->snapshot->complete()) ? AdapterVerdict::Accepted : AdapterVerdict::Partial;
            $code = 'acquisition.diagnostic';
            $signature = null;
            $rejection = $result instanceof FetchItemRejected ? $result->assessment
                : ($result instanceof ListingFetched && ! $result->page->assessment->usable() ? $result->page->assessment : null);
            if ($rejection !== null && $rejection->failureScope !== FailureScope::Item) {
                $verdict = AdapterVerdict::ContractFailure;
                $code = 'acquisition.'.$rejection->reason->value;
                $signature = ContentHash::of($contract->fingerprint()->hex.':'.$code);
            }
            $inspection = new AdapterInspection($result, $verdict, $code, $signature);
            $receipt = $this->transactions->run(fn () => $this->health->observe($owner, $inputHash, $inspection));
            if ($receipt->stale) {
                throw new OutboundOwnershipLost('Diagnostic owner changed before observation.');
            }

            return new DiagnosticResponse($result, $receipt, $requestsUsed);
        } finally {
            $this->transactions->run(fn () => $this->health->release($owner));
            $this->transactions->run(fn () => $this->operations->close($handle));
        }
    }
}
