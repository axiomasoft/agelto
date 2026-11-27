<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition\Status;

use App\Dto\Procurement\Acquisition\Control\StatusResult;
use App\Dto\Procurement\Acquisition\Detail\DetailFetched;
use App\Dto\Procurement\Acquisition\Detail\DetailRequest;
use App\Dto\Procurement\Acquisition\Fetch\FetchBlocked;
use App\Dto\Procurement\Acquisition\Fetch\FetchDeferred;
use App\Dto\Procurement\Acquisition\Fetch\FetchItemRejected;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Inputs\StatusRefreshInput;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
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
