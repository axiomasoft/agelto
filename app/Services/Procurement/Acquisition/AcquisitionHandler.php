<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Procurement\Acquisition\AcquisitionConfiguration;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\PlatformDefinition;
use App\Dto\Procurement\Platform\SupportedDetailCapability;
use App\Dto\Procurement\Platform\SupportedListingCapability;
use App\Dto\Procurement\Platform\SupportedListingPartitioningCapability;
use App\Dto\Procurement\Platform\SupportedStatusCapability;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ListingInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StatusRefreshInput;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Platform\Contracts\DetailPartsSource;
use App\Services\Procurement\Platform\Contracts\ListingFilterCodec;
use App\Services\Procurement\Platform\Contracts\ListingPartitioner;
use App\Services\Procurement\Platform\Contracts\ListingSource;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Platform\Contracts\StatusSource;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Proxy\ProxyResolver;
use Illuminate\Support\Str;

/** Per-work composition from the sole platform registry and the immutable run settings. */
final readonly class AcquisitionHandler implements StageHandler
{
    public function __construct(private StageCode $stage, private PlatformRegistry $platforms,
        private AcquisitionValidation $validation, private ProxyResolver $routes, private ?UrlPolicy $urls = null) {}

    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        if ($input->stage() !== $this->stage || (! $input instanceof ListingInput && ! $input instanceof DetailInput && ! $input instanceof StatusRefreshInput)) {
            throw new \InvalidArgumentException('Wrong acquisition stage input.');
        }
        $run = LedgerRow::one('SELECT p.code, p.id AS platform_id, r.configuration_snapshot, r.configuration_schema_version
            FROM prc_process_work w JOIN prc_platforms p ON p.id=w.platform_id
            JOIN prc_process_runs r ON r.id=w.origin_run_id WHERE w.id=?', [$execution->lease()->workId->value->value]);
        if ($run === null || ! in_array($run->int('configuration_schema_version'), [1, 2], true)) {
            return new StageBlocked(BlockedReason::PolicyReview);
        }
        $definition = $this->platforms->get(new PlatformCode($run->string('code')));
        if ($definition->version->value !== $input->adapterVersion) {
            return new StageBlocked(BlockedReason::HandlerHold);
        }
        $settings = AcquisitionConfiguration::decode(
            new VersionedPayload($run->string('configuration_snapshot'), $run->int('configuration_schema_version')));
        $profileId = $settings->routeProfileId;
        $route = LedgerRow::one('SELECT policy_version FROM prc_network_route_profiles WHERE id=?', [$profileId->value]);
        if ($route === null || $route->int('policy_version') !== $settings->routePolicyVersion) {
            return new StageBlocked(BlockedReason::PolicyReview);
        }
        $capability = match ($this->stage) {
            StageCode::Listing => $definition->listing,
            StageCode::Detail => $definition->detail,
            StageCode::StatusRefresh => $definition->status,
            default => throw new \LogicException('Unsupported acquisition stage.'),
        };
        if (! $capability instanceof SupportedListingCapability && ! $capability instanceof SupportedDetailCapability && ! $capability instanceof SupportedStatusCapability) {
            return new StageBlocked(BlockedReason::PolicyReview);
        }
        $endpoint = $definition->endpoints->get($capability->endpoints[0]);
        $target = $definition->sourceTargets->targets[$endpoint->targetKey] ?? throw new \LogicException('Missing endpoint target.');
        $contract = $target->adapterContract ?? throw new \LogicException('Acquisition requires an adapter contract.');
        $catalog = LedgerRow::one('SELECT id FROM prc_source_targets WHERE platform_id=? AND target_key=? AND retired_at IS NULL
            AND endpoint_policy_schema_version=2 AND endpoint_policy->\'adapter_contract\'=?::jsonb',
            [$run->int('platform_id'), $target->key, json_encode($contract->toArray(), JSON_THROW_ON_ERROR)]);
        if ($catalog === null) {
            return new StageBlocked(BlockedReason::PolicyReview);
        }
        $targetId = new ProcessUuid($catalog->string('id'));
        $traffic = $this->stage === StageCode::StatusRefresh ? TrafficClass::StatusRefresh
            : ($this->stage === StageCode::Detail ? TrafficClass::Detail
                : ($settings->kind === AcquisitionKind::Archive ? TrafficClass::ArchiveListing : TrafficClass::FreshListing));
        $first = new OutboundRequestPlan(new ProcessUuid((string) Str::uuid()), $targetId, 'GET',
            new SafeUrl($endpoint->url), $endpoint->profile, $traffic, IdempotencyClass::ReadOnly);

        return $this->validation->run($contract, $run->int('platform_id'), $this->routes->profile($profileId), $first,
            $this->urls ?? new UrlPolicy($definition->sourceTargets->origins()), $execution,
            function (PlatformOutbound $outbound, AcquisitionAssessment $assessment) use ($capability, $definition, $targetId, $input, $execution): StageOutcome {
                // An isolated resolution container propagates typed request dependencies through
                // adapter-private helpers without mutating the long-lived worker container.
                $container = clone app();
                $container->instance(PlatformDefinition::class, $definition);
                $container->instance(PlatformOutbound::class, $outbound);
                $container->instance(ProcessUuid::class, $targetId);
                $source = $container->make($capability->implementation);
                if ($input instanceof ListingInput) {
                    if (! $source instanceof ListingSource || ! $definition->partitioning instanceof SupportedListingPartitioningCapability) {
                        throw new \LogicException('Invalid listing composition.');
                    }
                    $partitioner = app($definition->partitioning->implementation);
                    if (! $partitioner instanceof ListingPartitioner || ! $partitioner instanceof ListingFilterCodec) {
                        throw new \LogicException('Missing listing filter codec.');
                    }
                    $handler = app()->makeWith(ListingHandler::class, ['source' => AcquisitionSources::listing($source, $assessment), 'partitioner' => $partitioner, 'filters' => $partitioner]);
                } elseif ($input instanceof DetailInput) {
                    if (! $source instanceof DetailPartsSource) {
                        throw new \LogicException('Missing detail parts capability.');
                    }
                    $handler = app()->makeWith(DetailHandler::class, ['source' => AcquisitionSources::detail($source, $assessment)]);
                } else {
                    if (! $source instanceof StatusSource) {
                        throw new \LogicException('Missing status capability.');
                    }
                    $handler = app()->makeWith(StatusRefreshHandler::class, ['source' => AcquisitionSources::status($source, $assessment)]);
                }

                return $handler->execute($input, $execution);
            });
    }
}
