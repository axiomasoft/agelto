<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Procurement\Acquisition\DetailFetched;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Acquisition\FetchBlocked;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Acquisition\FetchItemRejected;
use App\Dto\Procurement\Acquisition\StatusResult;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\StatusRefreshInput;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\BlockedReason;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Repositories\Procurement\Crawler\RefreshRepository;
use App\Services\Procurement\Platform\Contracts\StatusSource;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\Contracts\StageHandler;

final readonly class StatusRefreshHandler implements StageHandler
{
    public function __construct(private RefreshRepository $refreshes, private DetailSnapshotRepository $details,
        private StatusSource $source, private FetchOutcomeBridge $bridge) {}

    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        if (! $input instanceof StatusRefreshInput) {
            throw new \InvalidArgumentException('Status input required.');
        }
        $intent = $this->refreshes->intent($input);
        $saved = $this->details->request($input->procurementId, $intent);
        $request = new DetailRequest($saved->registry, $saved->identity, $saved->locator, statusOnly: true);
        $execution->tick();
        $fetched = $this->source->refresh($request, $execution);
        if (! $fetched instanceof DetailFetched) {
            return match (true) {
                $fetched instanceof FetchDeferred, $fetched instanceof FetchBlocked, $fetched instanceof FetchItemRejected => $this->bridge->failure($fetched),
                default => throw new \LogicException('Unknown status outcome.'),
            };
        }
        if ($fetched->snapshot->identity->toArray() !== $request->identity->toArray()) {
            throw new \LogicException('Status identity mismatch.');
        }
        if (! $fetched->snapshot->complete()) {
            return new StageBlocked(BlockedReason::PolicyReview);
        }

        return new StageSucceeded(new StatusResult($fetched->snapshot, new \DateTimeImmutable));
    }
}
