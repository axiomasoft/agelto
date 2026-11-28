<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document\Prepare;

use App\Dto\Procurement\Document\Download\DocumentRange;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Procurement\Document\CoverageUnit;
use App\Enums\Procurement\Document\PageTextStatus;
use App\Enums\Procurement\Process\Core\StageCode;

/** Immutable progress; text stays in verified artifacts, never in the work payload. */
final readonly class PrepareOcrCheckpoint implements StageCheckpoint
{
    /** @var list<PrepareRange> */
    public array $pending;

    /** @var list<PreparedBatch> */
    public array $batches;

    /** @var list<PreparePage> */
    public array $failed;

    /**
     * @param  array<array-key,PrepareRange>  $pending
     * @param  array<array-key,PreparedBatch>  $batches
     * @param  array<array-key,PreparePage>  $failed
     */
    public function __construct(public ?ContentHash $sourceHash = null, public ?ContentHash $recipeHash = null,
        public int $originalPages = 0, public int $pageLimit = 0, array $pending = [],
        array $batches = [], array $failed = [], public int $nextBatchSize = 5)
    {
        if (! array_is_list($pending) || ! array_is_list($batches) || ! array_is_list($failed)) {
            throw new \InvalidArgumentException('Manifest lists required.');
        }
        $this->pending = $pending;
        $this->batches = $batches;
        $this->failed = $failed;
        if ($sourceHash === null || $recipeHash === null) {
            if ($sourceHash !== null || $recipeHash !== null || $originalPages !== 0 || $pageLimit !== 0 || $pending !== [] || $batches !== [] || $failed !== []) {
                throw new \InvalidArgumentException('Only an empty checkpoint may be unbound.');
            }

            return;
        }
        if ($originalPages < 1 || $originalPages > 100000 || $pageLimit < 1 || $pageLimit > $originalPages || $nextBatchSize < 1 || $nextBatchSize > 20
        ) {
            throw new \InvalidArgumentException('Invalid preparation manifest.');
        }
        $seen = [];
        $add = static function (int $page) use (&$seen, $pageLimit): void {
            if ($page < 1 || $page > $pageLimit || isset($seen[$page])) {
                throw new \InvalidArgumentException('Overlapping or invalid page coverage.');
            }
            $seen[$page] = true;
        };
        foreach ($pending as $range) {
            for ($p = $range->start; $p < $range->end; $p++) {
                $add($p + 1);
            }
        }
        foreach ($batches as $batch) {
            foreach ($batch->pages as $page) {
                $add($page->page);
            }
        }
        foreach ($failed as $page) {
            if ($page->status !== PageTextStatus::Failed) {
                throw new \InvalidArgumentException('Failed page status required.');
            }
            $add($page->page);
        }
        if (count($seen) !== $pageLimit) {
            throw new \InvalidArgumentException('Unaccounted source pages.');
        }
    }

    public function stage(): StageCode
    {
        return StageCode::Prepare;
    }

    public function truncated(): bool
    {
        return $this->originalPages > $this->pageLimit;
    }

    /** @return list<PreparePage> */
    public function pages(): array
    {
        $pages = $this->failed;
        foreach ($this->batches as $batch) {
            array_push($pages, ...$batch->pages);
        }
        for ($p = $this->pageLimit + 1; $p <= $this->originalPages; $p++) {
            $pages[] = new PreparePage($p, PageTextStatus::NotAttempted);
        }
        usort($pages, static fn (PreparePage $a, PreparePage $b): int => $a->page <=> $b->page);

        return $pages;
    }

    public function coverage(): RepresentationCoverage
    {
        if ($this->pending !== []) {
            throw new \LogicException('Pending manifest has no final coverage.');
        }
        $covered = [];
        $missing = [];
        foreach ($this->pages() as $p) {
            $range = new DocumentRange($p->page - 1, $p->page);
            if ($p->status->covered()) {
                $covered[] = $range;
            } else {
                $missing[] = $range;
            }
        }

        return new RepresentationCoverage(CoverageUnit::Page, $this->originalPages, $covered, $missing);
    }

    /** An operational label, never a completeness or semantic accuracy override. */
    public function qualityLabel(float $threshold): string
    {
        $bad = count(array_filter($this->pages(), static fn (PreparePage $p): bool => in_array($p->status, [PageTextStatus::Failed, PageTextStatus::Unrecognized], true)));

        return $bad === 0 ? 'complete' : ($bad / $this->originalPages <= $threshold ? 'partial' : 'degraded');
    }
}
