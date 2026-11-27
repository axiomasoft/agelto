<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition\Listing;

use App\Dto\Procurement\Acquisition\Detail\DetailFetchResult;
use App\Dto\Procurement\Acquisition\Detail\DetailRequest;
use App\Dto\Procurement\Acquisition\Fetch\FetchItemRejected;
use App\Dto\Procurement\Acquisition\Listing\ListingFetchResult;
use App\Dto\Procurement\Acquisition\Listing\ListingRequest;
use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Platform\Capabilities\SupportedDetailCapability;
use App\Dto\Procurement\Platform\Capabilities\SupportedListingCapability;
use App\Dto\Procurement\Platform\Capabilities\SupportedStatusCapability;
use App\Dto\Procurement\Platform\Definition\PlatformDefinition;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Services\Procurement\Acquisition\Diagnostics\AcquisitionAssessment;
use App\Services\Procurement\Platform\Contracts\DetailPartsSource;
use App\Services\Procurement\Platform\Contracts\DetailSource;
use App\Services\Procurement\Platform\Contracts\ListingSource;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Platform\Contracts\StatusSource;

/** Request-local source composition and typed assessment wrappers for acquisition work. */
final class AcquisitionSources
{
    public static function resolve(
        PlatformDefinition $definition,
        SupportedListingCapability|SupportedDetailCapability|SupportedStatusCapability $capability,
        PlatformOutbound $outbound,
        ProcessUuid $targetId,
    ): ListingSource|DetailSource|StatusSource {
        // Propagate request dependencies through adapter-private helpers without changing the worker container.
        $container = clone app();
        $container->instance(PlatformDefinition::class, $definition);
        $container->instance(PlatformOutbound::class, $outbound);
        $container->instance(ProcessUuid::class, $targetId);
        $source = $container->make($capability->implementation);
        if (! $source instanceof ListingSource && ! $source instanceof DetailSource && ! $source instanceof StatusSource) {
            throw new \LogicException('Invalid acquisition source binding.');
        }

        return $source;
    }

    public static function listing(ListingSource $source, AcquisitionAssessment $assessment): ListingSource
    {
        return new readonly class($source, $assessment) implements ListingSource
        {
            public function __construct(private ListingSource $source, private AcquisitionAssessment $assessment) {}

            public function fetch(ListingRequest $request, ExecutionContext|OutboundExecutionContext $context): ListingFetchResult
            {
                $result = $this->source->fetch($request, $context);
                if ($result instanceof FetchItemRejected) {
                    $result = $this->assessment->rejection($result);
                }
                $this->assessment->observe($result);

                return $result;
            }
        };
    }

    public static function detail(DetailPartsSource $source, AcquisitionAssessment $assessment): DetailPartsSource
    {
        return new readonly class($source, $assessment) implements DetailPartsSource
        {
            public function __construct(private DetailPartsSource $source, private AcquisitionAssessment $assessment) {}

            public function parts(DetailRequest $request): array
            {
                return $this->source->parts($request);
            }

            public function fetch(DetailRequest $request, ExecutionContext|OutboundExecutionContext $context): DetailFetchResult
            {
                $result = $this->source->fetch($request, $context);
                if ($result instanceof FetchItemRejected) {
                    $result = $this->assessment->rejection($result);
                }
                $this->assessment->observe($result);

                return $result;
            }

            public function fetchPart(DetailRequest $request, string $part, ExecutionContext|OutboundExecutionContext $context): DetailFetchResult
            {
                $result = $this->source->fetchPart($request, $part, $context);
                if ($result instanceof FetchItemRejected) {
                    $result = $this->assessment->rejection($result);
                }
                $this->assessment->observe($result);

                return $result;
            }
        };
    }

    public static function status(StatusSource $source, AcquisitionAssessment $assessment): StatusSource
    {
        return new readonly class($source, $assessment) implements StatusSource
        {
            public function __construct(private StatusSource $source, private AcquisitionAssessment $assessment) {}

            public function refresh(DetailRequest $request, ExecutionContext|OutboundExecutionContext $context): DetailFetchResult
            {
                $result = $this->source->refresh($request, $context);
                if ($result instanceof FetchItemRejected) {
                    $result = $this->assessment->rejection($result);
                }
                $this->assessment->observe($result);

                return $result;
            }
        };
    }
}
