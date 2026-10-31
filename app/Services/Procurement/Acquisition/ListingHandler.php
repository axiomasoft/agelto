<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Procurement\Acquisition\FetchBlocked;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Acquisition\FetchItemRejected;
use App\Dto\Procurement\Acquisition\ListingFetched;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Acquisition\ListingResult;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ListingInput;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Process\BlockedReason;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Services\Procurement\Platform\Contracts\ListingFilterCodec;
use App\Services\Procurement\Platform\Contracts\ListingPartitioner;
use App\Services\Procurement\Platform\Contracts\ListingSource;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\Contracts\StageHandler;

/** One bounded page per work. Composition supplies the registered platform capability. No DB writes or network in a transaction. */
final readonly class ListingHandler implements StageHandler
{
    public function __construct(private ListingStoreRepository $store, private ListingSource $source,
        private ListingPartitioner $partitioner, private ListingFilterCodec $filters, private FetchOutcomeBridge $bridge) {}

    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        if (! $input instanceof ListingInput) {
            throw new \InvalidArgumentException('Listing input required.');
        }
        $task = $this->store->task($input);
        $execution->tick();
        $result = $this->source->fetch(new ListingRequest($task->registry, $this->filters->restoreFilter($task->filter),
            $task->cursor, $this->store->observedPages($task), $this->store->kind($input)), $execution);
        if (! $result instanceof ListingFetched) {
            return match (true) {
                $result instanceof FetchBlocked,
                $result instanceof FetchDeferred,
                $result instanceof FetchItemRejected => $this->bridge->failure($result),
                default => throw new \LogicException('Unknown listing fetch outcome.'),
            };
        }
        $page = $result->page;
        if (in_array($page->assessment->reason, [AssessmentReason::Overflow, AssessmentReason::RepeatedPage], true)) {
            return new StageSucceeded(new ListingResult($task, $page, $this->partitioner->split($task->rootCursor, 1)));
        }
        if (! $page->assessment->usable()) {
            // Invalid response cannot advance cursor or masquerade as a completed page.
            return new StageBlocked(BlockedReason::HandlerHold);
        }

        return new StageSucceeded(new ListingResult($task, $page));
    }
}
