<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\Contract;

use App\Dto\Procurement\Acquisition\Detail\DetailFetched;
use App\Dto\Procurement\Acquisition\Detail\DetailFetchResult;
use App\Dto\Procurement\Acquisition\Fetch\FetchItemRejected;
use App\Dto\Procurement\Acquisition\Listing\ListingPage;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\TerminalProof;

/** Golden semantic projection deliberately excludes wall-clock fetch timestamps. */
final readonly class SemanticObservation
{
    /**
     * @param  list<string>  $identities
     * @param  list<string>  $titles
     * @param  list<string>  $stages
     * @param  list<string|null>  $prices
     * @param  array<string,string>  $parts
     */
    public function __construct(
        public Completeness $completeness,
        public AssessmentReason $reason,
        public int $rows = 0,
        public TerminalProof $terminal = TerminalProof::None,
        public ?string $cursor = null,
        public array $identities = [],
        public array $titles = [],
        public array $stages = [],
        public array $prices = [],
        public array $parts = [],
        public int $parties = 0,
        public int $documents = 0,
        public bool $deterministic = true,
    ) {}

    public static function listing(ListingPage $page, bool $deterministic = true): self
    {
        $identities = $titles = $stages = $prices = [];
        foreach ($page->summaries->items as $summary) {
            $identities[] = $summary->identity->platform->value.'/'.$summary->identity->type->value.'/'.$summary->identity->value->value;
            $titles[] = $summary->title;
            $stages[] = $summary->sourceStage;
            $prices[] = $summary->initialPrice;
        }

        return new self($page->assessment->completeness, $page->assessment->reason, $page->rowsTotal,
            $page->terminalProof, $page->nextCursor?->serializedPayload, $identities, $titles, $stages, $prices,
            deterministic: $deterministic);
    }

    public static function detail(DetailFetchResult $result): self
    {
        if ($result instanceof FetchItemRejected) {
            return new self($result->assessment->completeness, $result->assessment->reason);
        }
        if (! $result instanceof DetailFetched) {
            throw new \LogicException('Golden detail unexpectedly returned a dependency outcome.');
        }
        $snapshot = $result->snapshot;
        $parts = [];
        foreach ($snapshot->parts as $part) {
            $parts[$part->name] = $part->completeness->value;
        }

        return new self($snapshot->complete() ? Completeness::Complete : Completeness::Partial,
            $snapshot->complete() ? AssessmentReason::Valid : AssessmentReason::MissingSection,
            identities: [$snapshot->identity->platform->value.'/'.$snapshot->identity->type->value.'/'.$snapshot->identity->value->value],
            titles: [$snapshot->title], stages: [$snapshot->sourceStage], parts: $parts,
            parties: count($snapshot->parties), documents: count($snapshot->documents));
    }
}
