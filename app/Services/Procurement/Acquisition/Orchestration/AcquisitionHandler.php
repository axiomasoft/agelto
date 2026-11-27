<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition\Orchestration;

use App\Dto\Procurement\Acquisition\Control\AcquisitionConfiguration;
use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Dto\Procurement\Platform\Capabilities\SupportedDetailCapability;
use App\Dto\Procurement\Platform\Capabilities\SupportedListingCapability;
use App\Dto\Procurement\Platform\Capabilities\SupportedListingPartitioningCapability;
use App\Dto\Procurement\Platform\Capabilities\SupportedStatusCapability;
use App\Dto\Procurement\Platform\Definition\PlatformDefinition;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Inputs\DetailInput;
use App\Dto\Procurement\Process\Inputs\ListingInput;
use App\Dto\Procurement\Process\Inputs\StatusRefreshInput;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Repositories\Procurement\Network\SourceCatalogRepository;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Acquisition\Detail\DetailHandler;
use App\Services\Procurement\Acquisition\Diagnostics\AcquisitionAssessment;
use App\Services\Procurement\Acquisition\Diagnostics\AcquisitionValidation;
use App\Services\Procurement\Acquisition\Listing\AcquisitionSources;
use App\Services\Procurement\Acquisition\Listing\ListingHandler;
use App\Services\Procurement\Acquisition\Status\StatusRefreshHandler;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Platform\Contracts\DetailPartsSource;
use App\Services\Procurement\Platform\Contracts\ListingFilterCodec;
use App\Services\Procurement\Platform\Contracts\ListingPartitioner;
use App\Services\Procurement\Platform\Contracts\ListingSource;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Platform\Contracts\StatusSource;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Proxyma\Catalog\BindingAdapter;
use App\Services\Procurement\Proxyma\Catalog\ProfileResolver;
use Illuminate\Support\Str;

/** Per-work composition from the sole platform registry and the immutable run settings. */
final readonly class AcquisitionHandler implements StageHandler
{
    public function __construct(private StageCode $stage, private PlatformRegistry $platforms,
        private AcquisitionValidation $validation, private ProfileResolver $routes,
        private SourceCatalogRepository $catalog, private ?UrlPolicy $urls = null) {}

    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        if ($input->stage() !== $this->stage || (! $input instanceof ListingInput && ! $input instanceof DetailInput && ! $input instanceof StatusRefreshInput)) {
            throw new \InvalidArgumentException('Wrong acquisition stage input.');
        }
        $run = LedgerRow::one('SELECT p.code, p.id AS platform_id, r.configuration_snapshot, r.configuration_schema_version
            FROM prc_process_work w JOIN prc_platforms p ON p.id=w.platform_id
            JOIN prc_process_runs r ON r.id=w.origin_run_id WHERE w.id=?', [$execution->lease()->workId->value->value]);
        if ($run === null || ! in_array($run->int('configuration_schema_version'), [1, 2, 3], true)) {
            return new StageBlocked(BlockedReason::PolicyReview);
        }
        $definition = $this->platforms->get(new PlatformCode($run->string('code')));
        if ($definition->version->value !== $input->adapterVersion) {
            return new StageBlocked(BlockedReason::HandlerHold);
        }
        $settings = AcquisitionConfiguration::decode(
            new VersionedPayload($run->string('configuration_snapshot'), $run->int('configuration_schema_version')));
        $profileId = $settings->routeProfileIdForStage($this->stage);
        $expectedPolicyVersion = $settings->routePolicyVersionForStage($this->stage);
        try {
            app(BindingAdapter::class)->snapshotProfile($settings, $this->stage);
        } catch (\DomainException) {
            return new StageBlocked(BlockedReason::PolicyReview);
        }
        $profileId = $this->resolveStageRouteProfileId($definition, $settings);
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
        $targetId = $this->catalog->findActiveAdapterTarget(
            platformId: $run->int('platform_id'),
            targetKey: $target->key,
            contract: $contract,
        );
        if ($targetId === null) {
            return new StageBlocked(BlockedReason::PolicyReview);
        }
        $traffic = $this->stage === StageCode::StatusRefresh ? TrafficClass::StatusRefresh
            : ($this->stage === StageCode::Detail ? TrafficClass::Detail
                : ($settings->kind === AcquisitionKind::Archive ? TrafficClass::ArchiveListing : TrafficClass::FreshListing));
        $first = new OutboundRequestPlan(new ProcessUuid((string) Str::uuid()), $targetId, 'GET',
            new SafeUrl($endpoint->url), $endpoint->profile, $traffic, IdempotencyClass::ReadOnly);

        return $this->validation->run($contract, $run->int('platform_id'), $this->routes->profile($profileId), $first,
            $this->urls ?? new UrlPolicy($definition->sourceTargets->origins()), $execution,
            function (PlatformOutbound $outbound, AcquisitionAssessment $assessment) use ($capability, $definition, $targetId, $input, $execution): StageOutcome {
                $source = AcquisitionSources::resolve(
                    definition: $definition,
                    capability: $capability,
                    outbound: $outbound,
                    targetId: $targetId,
                );
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

    private function resolveStageRouteProfileId(PlatformDefinition $definition, AcquisitionConfiguration $settings): ProcessUuid
    {
        $profileId = $settings->routeProfileIdForStage($this->stage);
        if (! $settings->hasStageRouteProfile($this->stage)) {
            $stageProfileKey = $definition->stageRouteProfileKey($this->stage);
            if ($stageProfileKey !== null) {
                $stageRoute = app(ProfileResolver::class)->snapshot(key: $stageProfileKey);
                if ($stageRoute !== null) {
                    return new ProcessUuid($stageRoute->string('id'));
                }
            }
        }

        return $profileId;
    }
}
