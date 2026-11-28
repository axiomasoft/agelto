<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition\Detail;

use App\Dto\Procurement\Acquisition\Detail\DetailFetched;
use App\Dto\Procurement\Acquisition\Detail\DetailProgress;
use App\Dto\Procurement\Acquisition\Detail\DetailResult;
use App\Dto\Procurement\Acquisition\Detail\ProcurementSnapshot;
use App\Dto\Procurement\Acquisition\Fetch\FetchBlocked;
use App\Dto\Procurement\Acquisition\Fetch\FetchDeferred;
use App\Dto\Procurement\Acquisition\Fetch\FetchItemRejected;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Inputs\DetailInput;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Outcomes\StagePartial;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Dto\Procurement\Process\Primitives\MissingParts;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Services\Procurement\Platform\Contracts\DetailPartsSource;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\Contracts\StageHandler;

final readonly class DetailHandler implements StageHandler
{
    public function __construct(
        private DetailSnapshotRepository $store,
        private DetailPartsSource $source,
        private DetailArtifacts $artifacts,
        private FetchOutcomeBridge $bridge,
        private ?string $activeRulesHash = null,
    ) {}

    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        if (! $input instanceof DetailInput) {
            throw new \InvalidArgumentException('Detail input required.');
        }
        $request = $this->store->request($input->procurementId, $input->intentId);
        $progress = $execution->lease()->checkpoint->stageProgress ?? new DetailProgress(rulesHash: $this->activeRulesHash);
        if (! $progress instanceof DetailProgress) {
            throw new \LogicException('Invalid detail progress.');
        }
        if ($progress->parts !== [] && $progress->rulesHash !== null && $this->activeRulesHash !== null && $progress->rulesHash !== $this->activeRulesHash) {
            $progress = new DetailProgress(parts: [], pass: $progress->pass, failures: 0, rulesHash: $this->activeRulesHash);
        }
        $parts = $this->partNames($this->source->parts($request));
        if (array_diff(array_keys($progress->parts), $parts) !== []) {
            throw new \LogicException('Invalid adapter part contract.');
        }
        $snapshots = [];
        foreach ($parts as $part) {
            if (isset($progress->parts[$part])) {
                $snapshots[] = $this->artifacts->read($progress->parts[$part]);

                continue;
            }
            $execution->tick();
            $fetched = $this->source->fetchPart($request, $part, $execution);
            if (! $fetched instanceof DetailFetched) {
                return match (true) {
                    $fetched instanceof FetchDeferred, $fetched instanceof FetchBlocked, $fetched instanceof FetchItemRejected => $this->bridge->failure($fetched),
                    default => throw new \LogicException('Unknown detail outcome.'),
                };
            }
            $snapshot = $fetched->snapshot;
            if ($snapshot->identity->toArray() !== $request->identity->toArray()) {
                throw new \LogicException('Detail identity mismatch.');
            }
            if (! $snapshot->complete()) {
                $progress = new DetailProgress($progress->parts, $progress->pass, $progress->failures + 1, rulesHash: $this->activeRulesHash ?? $progress->rulesHash);
                $execution->checkpoint($progress);

                return $progress->failures === 3 ? new StageBlocked(BlockedReason::PolicyReview)
                    : new StagePartial($progress, new MissingParts([$part]));
            }
            $ref = $this->artifacts->put($snapshot);
            $progress = new DetailProgress([...$progress->parts, $part => $ref], $progress->pass, $progress->failures, rulesHash: $this->activeRulesHash ?? $progress->rulesHash);
            $execution->checkpoint($progress);
            $snapshots[] = $snapshot;
        }
        $execution->tick();
        $control = $this->source->fetchPart($request, $parts[0], $execution);
        if (! $control instanceof DetailFetched) {
            return match (true) {
                $control instanceof FetchDeferred, $control instanceof FetchBlocked, $control instanceof FetchItemRejected => $this->bridge->failure($control),
                default => throw new \LogicException('Unknown control outcome.'),
            };
        }
        $first = $snapshots[0];
        $stable = $control->snapshot->complete() && $control->snapshot->identity->toArray() === $request->identity->toArray()
            && $this->digest($first) === $this->digest($control->snapshot);
        foreach ($snapshots as $snapshot) {
            $stable = $stable && $snapshot->sourceRevision === $first->sourceRevision;
        }
        if (! $stable) {
            if ($progress->pass === 3) {
                $execution->checkpoint(new DetailProgress($progress->parts, $progress->pass, $progress->failures, SnapshotConsistency::Unstable, rulesHash: $this->activeRulesHash ?? $progress->rulesHash));

                return new StageBlocked(BlockedReason::PolicyReview);
            }

            return new StagePartial(new DetailProgress(pass: $progress->pass + 1, rulesHash: $this->activeRulesHash ?? $progress->rulesHash), new MissingParts($parts));
        }

        // The fresh control can carry the price even if the saved common-info predates this field.
        return new StageSucceeded(new DetailResult($this->combine(snapshots: $snapshots, initialPrice: $control->snapshot->initialPrice), $progress,
            $first->sourceRevision === null ? SnapshotConsistency::BoundedRevalidated : SnapshotConsistency::SourceRevisionVerified, new \DateTimeImmutable));
    }

    private function digest(ProcurementSnapshot $s): string
    {
        return hash('sha256', json_encode([$s->identity->toArray(), $s->sourceRevision, $s->title, $s->sourceStage,
            array_map(static fn ($p): string => $p->name.':'.$p->bodyHash->hex, $s->parts)], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $parts
     * @return non-empty-list<string>
     */
    private function partNames(array $parts): array
    {
        if ($parts === [] || count($parts) > 8 || count(array_unique($parts)) !== count($parts)) {
            throw new \LogicException('Invalid adapter part contract.');
        }
        foreach ($parts as $part) {
            if (! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $part)) {
                throw new \LogicException('Invalid part name.');
            }
        }

        return $parts;
    }

    /** @param non-empty-list<ProcurementSnapshot> $snapshots */
    private function combine(array $snapshots, ?string $initialPrice): ProcurementSnapshot
    {
        $first = $snapshots[0];
        $parties = $items = $financing = $documents = $parts = $events = [];
        $results = null;
        foreach ($snapshots as $s) {
            array_push($parties, ...$s->parties);
            array_push($items, ...$s->items);
            array_push($financing, ...$s->financing);
            array_push($documents, ...$s->documents);
            array_push($parts, ...$s->parts);
            array_push($events, ...$s->events);
            $results = $s->results ?? $results;
        }

        return new ProcurementSnapshot($first->identity, $first->title, $first->sourceStage, $parties, $items, $financing, $documents,
            $parts, $first->extension, $first->sourceRevision, $events, $results, initialPrice: $initialPrice);
    }
}
